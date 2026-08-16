<?php

declare(strict_types=1);

namespace App\Services;

use App\DataObjects\Entitlements;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The single place that decides what a user may do — `docs/03-BILLING.md` §10.
 *
 * Cached, because EnsureCanWrite consults it on EVERY mutating request. At the
 * target load an uncached database read here is thousands of extra queries per
 * second that all re-derive a value which changes about once a month.
 *
 * A 15-minute TTL sits under the explicit invalidation so that a missed
 * `forget()` self-heals rather than leaving someone locked out (or, worse, let
 * in) indefinitely.
 *
 * `final` on purpose: tests seed the cache key rather than mocking, which
 * exercises the real resolution path instead of a stand-in that can drift.
 */
final class EntitlementService
{
    private const TTL_SECONDS = 900;

    public function for(int $userId): Entitlements
    {
        $cached = Cache::get(self::cacheKey($userId));

        if ($cached instanceof Entitlements) {
            return $cached;
        }

        $entitlements = $this->resolve($userId);

        Cache::put(self::cacheKey($userId), $entitlements, self::TTL_SECONDS);

        return $entitlements;
    }

    public function forget(int $userId): void
    {
        Cache::forget(self::cacheKey($userId));
    }

    public static function cacheKey(int $userId): string
    {
        return "user:{$userId}:entitlements";
    }

    /**
     * Derive from the subscription. Read the status table in
     * `docs/03-BILLING.md` §5 alongside this.
     */
    private function resolve(int $userId): Entitlements
    {
        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()
            ->with('plan')
            ->where('user_id', $userId)
            ->nonTerminal()
            ->latest('id')
            ->first();

        if (! $subscription instanceof Subscription || $subscription->plan === null) {
            return Entitlements::none();
        }

        $status = $subscription->status;
        $canWrite = $this->canWrite($subscription, $status);

        return new Entitlements(
            maxAccounts: $canWrite ? (int) $subscription->plan->max_accounts : 0,
            canWrite: $canWrite,
            canExternalTransfer: $canWrite && (bool) $subscription->plan->feature('external_transfers', true),
            // Always true — see Entitlements::toArray().
            canExport: true,
            planCode: (string) $subscription->plan->code,
            status: $status,
            trialEndsAt: $this->moment($subscription->trial_ends_at),
            currentPeriodEnd: $this->moment($subscription->current_period_end),
            graceEndsAt: $this->moment($subscription->grace_ends_at),
        );
    }

    private function canWrite(Subscription $subscription, SubscriptionStatus $status): bool
    {
        $now = CarbonImmutable::now();

        return match ($status) {
            // Trial: writable until it expires. The nightly sweep flips the
            // row, but the date is checked here too so a missed sweep does not
            // hand out free months.
            SubscriptionStatus::Trialing => $this->isFuture($subscription->trial_ends_at, $now),

            SubscriptionStatus::Active => true,

            // Cancelled but paid through the period — they keep what they paid for.
            SubscriptionStatus::Cancelled => $this->isFuture($subscription->current_period_end, $now),

            // Grace period. A UPI mandate failing for a day is routine in this
            // market — bank downtime, month-end balance — and locking out a
            // paying customer over it costs more in churn than the charge.
            //
            // FAILS OPEN when grace_ends_at is null: a webhook that set
            // past_due without a grace date must not silently lock someone out.
            SubscriptionStatus::PastDue => $subscription->grace_ends_at === null
                || $this->isFuture($subscription->grace_ends_at, $now),

            SubscriptionStatus::Paused,
            SubscriptionStatus::Expired => false,
        };
    }

    private function isFuture(mixed $value, CarbonImmutable $now): bool
    {
        $moment = $this->moment($value);

        return $moment !== null && $moment->greaterThan($now);
    }

    /**
     * Normalise whatever the cast handed back into a CarbonImmutable.
     *
     * The models cast timestamps as 'timestamp', which yields an INT, not a
     * Carbon — see the open decision in docs/09-BUILD-STATUS.md §3.1. Callers
     * must not assume a date object, so every consumer goes through here.
     */
    private function moment(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_int($value) || ctype_digit((string) $value)) {
            return CarbonImmutable::createFromTimestamp((int) $value);
        }

        return CarbonImmutable::parse((string) $value);
    }
}
