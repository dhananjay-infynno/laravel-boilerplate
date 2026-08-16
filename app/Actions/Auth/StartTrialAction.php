<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Starts the 30-day free trial — `docs/03-BILLING.md` §8.
 *
 * Invoked on EMAIL VERIFICATION, never on registration. That ordering is the
 * anti-farming measure: an unverified address costs nothing to create, a
 * verified one does not. Do not move this into register().
 *
 * A `trialing` subscription row is created alongside `users.trial_ends_at` so
 * that every entitlement check goes through one code path rather than
 * special-casing trials in a dozen places.
 */
final readonly class StartTrialAction
{
    private const DEFAULT_TRIAL_DAYS = 30;

    public function __construct(
        private EntitlementService $entitlements,
    ) {}

    /**
     * Idempotent — a user who somehow verifies twice does not get a second
     * trial. Returns null when one was already granted.
     */
    public function handle(User $user): ?Subscription
    {
        if ($this->alreadyHadTrial($user)) {
            return null;
        }

        /** @var Plan|null $plan */
        $plan = Plan::query()->where('code', 'trial')->first();

        if (! $plan instanceof Plan) {
            // The seeder has not run. Do NOT fail verification over it — the
            // user is verified either way, and support can grant a trial.
            Log::error('Trial plan missing: cannot start trial.', ['user_id' => $user->id]);

            return null;
        }

        $trialDays = (int) ($plan->trial_days ?: self::DEFAULT_TRIAL_DAYS);

        $subscription = DB::transaction(function () use ($user, $plan, $trialDays): Subscription {
            $endsAt = Carbon::now()->addDays($trialDays);

            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                // A trial has no price row and no gateway — it is never charged.
                'plan_price_id' => null,
                'gateway' => null,
                'status' => SubscriptionStatus::Trialing,
                'current_period_start' => Carbon::now(),
                'current_period_end' => $endsAt,
                'trial_ends_at' => $endsAt,
            ]);

            $user->forceFill(['trial_ends_at' => $endsAt])->save();

            return $subscription;
        });

        // Without this the cache keeps serving the "expired" entitlements
        // resolved before verification, and the user's first write 402s.
        $this->entitlements->forget((int) $user->id);

        return $subscription;
    }

    /**
     * Once per user, ever.
     *
     * Both markers are checked: either one being set means a trial was already
     * granted at some point, even if the subscription has since expired.
     */
    private function alreadyHadTrial(User $user): bool
    {
        if ($user->trial_ends_at !== null) {
            return true;
        }

        return Subscription::query()
            ->where('user_id', $user->id)
            ->where('status', SubscriptionStatus::Trialing)
            ->exists();
    }
}
