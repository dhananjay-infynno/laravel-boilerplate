<?php

declare(strict_types=1);

namespace App\Services;

use App\DataObjects\CheckoutIntent;
use App\DataObjects\WebhookEvent;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WebhookEventType;
use App\Exceptions\Domain\DowngradeBlockedException;
use App\Jobs\Billing\IssueInvoiceJob;
use App\Models\Account;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Billing\MandateRevoked as MandateRevokedNotice;
use App\Notifications\Billing\PaymentFailedNotice;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The subscription state machine — `docs/03-BILLING.md` §5.
 *
 *   trialing ──▶ active ──▶ past_due ──▶ expired
 *                  │            │
 *                  └── cancelled ┘
 *
 * THE RULE THAT MATTERS MOST:
 *
 *   Entitlements are granted ONLY by a verified webhook. Never by a client
 *   callback.
 *
 * The client's `verify` call is attacker-controlled input. Treating it as proof
 * of payment is the single most common way small SaaS products get free
 * subscriptions farmed off them — it exists here purely so the UI can say
 * "activating..." instead of polling.
 */
final readonly class SubscriptionService
{
    public function __construct(
        private PaymentGatewayManager $gateways,
        private EntitlementService $entitlements,
    ) {}

    /**
     * Start checkout. Creates the gateway subscription and a LOCAL row that is
     * not yet active — activation waits for the webhook.
     */
    public function checkout(User $user, string $planCode): CheckoutIntent
    {
        $gateway = $this->gateways->for($user);

        /** @var Plan $plan */
        $plan = Plan::query()->where('code', $planCode)->where('is_active', true)->firstOrFail();

        /** @var PlanPrice $price */
        $price = PlanPrice::query()
            ->where('plan_id', $plan->id)
            ->where('gateway', $gateway->code())
            ->where('currency_code', $user->currency_code ?: 'INR')
            ->where('is_active', true)
            ->firstOrFail();

        $this->guardDowngrade($user, $plan);

        $current = $this->currentFor((int) $user->id);

        $intent = $gateway->createSubscription($user, $price);

        DB::transaction(function () use ($user, $plan, $price, $gateway, $intent, $current): void {
            /*
             * Bin any earlier checkout row that was never paid for.
             *
             * Without this, a user who opens checkout, backs out, and opens it
             * again accumulates non-terminal rows. Entitlements resolve to the
             * LATEST non-terminal subscription, so the abandoned row would
             * outrank the one they actually paid for and revoke access from a
             * paying customer. The model already documents "at most one
             * non-terminal subscription" — this is what enforces it.
             */
            Subscription::query()
                ->where('user_id', $user->id)
                ->whereNotNull('gateway_subscription_id')
                ->where('status', SubscriptionStatus::Trialing)
                ->whereNull('last_payment_at')
                ->update(['status' => SubscriptionStatus::Expired]);

            Subscription::updateOrCreate(
                ['gateway' => $gateway->code(), 'gateway_subscription_id' => $intent->gatewaySubscriptionId],
                [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'plan_price_id' => $price->id,
                    'status' => SubscriptionStatus::Trialing,
                    /*
                     * The trial date is CARRIED OVER from the row this one
                     * supersedes, and that is not cosmetic.
                     *
                     * Entitlements read the newest non-terminal subscription. A
                     * fresh row with a null trial_ends_at resolves to "cannot
                     * write", so a user upgrading on day 3 of their trial would
                     * lose write access the instant they tapped Subscribe and
                     * get it back only when the webhook landed. They would be
                     * punished for paying.
                     *
                     * An expired user resubscribing carries over null, which is
                     * correct — they genuinely cannot write until payment
                     * confirms.
                     */
                    'trial_ends_at' => $current?->trial_ends_at,
                    'mandate_max_amount' => config('razorpay.mandate_max_amount'),
                ],
            );
        });

        $this->entitlements->forget((int) $user->id);

        return $intent;
    }

    /**
     * Apply a canonical webhook event.
     *
     * MUST be idempotent: gateways retry, and a duplicated `subscription.charged`
     * must not extend the period twice. The caller dedupes on
     * `payment_events.event_id`; this method also tolerates replays on its own.
     */
    public function applyWebhook(WebhookEvent $event): void
    {
        if ($event->isIgnorable()) {
            return;
        }

        $subscription = $this->locateSubscription($event);

        if (! $subscription instanceof Subscription) {
            // A webhook for a subscription we have never seen. Logged rather
            // than thrown: throwing makes Razorpay retry forever, and the
            // hourly reconcile job repairs genuine gaps.
            Log::warning('Webhook for unknown subscription.', [
                'event' => $event->type->value,
                'gateway_subscription_id' => $event->gatewaySubscriptionId,
            ]);

            return;
        }

        DB::transaction(function () use ($event, $subscription): void {
            match ($event->type) {
                WebhookEventType::SubscriptionActivated => $this->activate($subscription, $event),
                WebhookEventType::SubscriptionRenewed => $this->renew($subscription, $event),
                WebhookEventType::SubscriptionPaymentFailed => $this->markPastDue($subscription, $event),
                WebhookEventType::SubscriptionCancelled => $this->cancelLocal($subscription),
                WebhookEventType::SubscriptionPaused => $this->setStatus($subscription, SubscriptionStatus::Paused),
                WebhookEventType::SubscriptionResumed => $this->setStatus($subscription, SubscriptionStatus::Active),
                WebhookEventType::SubscriptionExpired => $this->setStatus($subscription, SubscriptionStatus::Expired),
                WebhookEventType::MandateRevoked => $this->revokeMandate($subscription),
                WebhookEventType::PaymentCaptured,
                WebhookEventType::PaymentFailed,
                WebhookEventType::PaymentRefunded,
                WebhookEventType::Unknown => null,
            };

            // Runs for EVERY event, not just the payment ones: `subscription.charged`
            // carries a payment entity too, and it is the only place a renewal
            // payment is ever reported. It no-ops when there is no payment id.
            $this->recordPayment($subscription, $event);
        });

        // Entitlements are cached, so every state change must bust them or the
        // user keeps whatever access they had for up to 15 minutes.
        $this->entitlements->forget((int) $subscription->user_id);
    }

    /**
     * Move an active subscription to another plan.
     *
     * The client does NOT get to say when this takes effect — the direction of
     * the change decides. Upgrades apply immediately because the user is paying
     * more and wants the accounts now; downgrades wait for the cycle end
     * because they already paid for the higher tier through this period.
     */
    public function changePlan(Subscription $subscription, string $planCode): Subscription
    {
        /** @var Plan $newPlan */
        $newPlan = Plan::query()->where('code', $planCode)->where('is_active', true)->firstOrFail();

        if ((int) $newPlan->id === (int) $subscription->plan_id) {
            return $subscription;
        }

        /** @var User $user */
        $user = $subscription->user()->firstOrFail();

        // Always checked, even on an upgrade: the "upgrade" may be a change of
        // billing interval that also lowers the account cap.
        $this->guardDowngrade($user, $newPlan);

        $gateway = $this->gateways->driver((string) $subscription->gateway);

        /** @var PlanPrice $newPrice */
        $newPrice = PlanPrice::query()
            ->where('plan_id', $newPlan->id)
            ->where('gateway', $gateway->code())
            ->where('currency_code', $user->currency_code ?: 'INR')
            ->where('is_active', true)
            ->firstOrFail();

        $currentMax = (int) ($subscription->plan?->max_accounts ?? 0);
        $isDowngrade = (int) $newPlan->max_accounts < $currentMax;

        $gateway->changePlan($subscription, $newPrice, atCycleEnd: $isDowngrade);

        /*
         * A downgrade does NOT rewrite plan_id yet — the gateway applies it at
         * the cycle end, and flipping the local row now would cut the user's
         * account limit while they are still paying the higher rate. The
         * webhook moves it when it actually happens.
         */
        if (! $isDowngrade) {
            $subscription->update([
                'plan_id' => $newPlan->id,
                'plan_price_id' => $newPrice->id,
            ]);
        } else {
            $subscription->update([
                'metadata' => array_merge((array) $subscription->metadata, [
                    'pending_plan_code' => (string) $newPlan->code,
                ]),
            ]);
        }

        $this->entitlements->forget((int) $subscription->user_id);

        return $subscription->refresh();
    }

    public function cancel(Subscription $subscription, bool $atPeriodEnd = true): Subscription
    {
        $gateway = $this->gateways->driver((string) $subscription->gateway);
        $gateway->cancelSubscription($subscription, $atPeriodEnd);

        $subscription->update([
            'cancel_at_period_end' => $atPeriodEnd,
            'cancelled_at' => CarbonImmutable::now(),
            // Immediate cancellation ends access now; otherwise the row stays
            // `cancelled` and entitlements survive to current_period_end.
            'status' => $atPeriodEnd ? SubscriptionStatus::Cancelled : SubscriptionStatus::Expired,
        ]);

        $this->entitlements->forget((int) $subscription->user_id);

        return $subscription->refresh();
    }

    public function resume(Subscription $subscription): Subscription
    {
        $this->gateways->driver((string) $subscription->gateway)->resumeSubscription($subscription);

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
        ]);

        $this->entitlements->forget((int) $subscription->user_id);

        return $subscription->refresh();
    }

    /**
     * Re-sync one subscription from the gateway.
     *
     * Webhooks get dropped and networks fail — assume the local state WILL
     * drift and repair it, rather than trusting the event stream was complete.
     */
    public function reconcile(Subscription $subscription): bool
    {
        if (blank($subscription->gateway_subscription_id)) {
            return false;
        }

        $remote = $this->gateways
            ->driver((string) $subscription->gateway)
            ->fetchSubscription((string) $subscription->gateway_subscription_id);

        $drifted = $remote->status !== $subscription->status;

        if ($drifted) {
            Log::warning('Subscription drift repaired.', [
                'subscription_id' => $subscription->id,
                'local' => $subscription->status->value,
                'gateway' => $remote->status->value,
            ]);
        }

        $subscription->update([
            'status' => $remote->status,
            'current_period_start' => $remote->currentPeriodStart,
            'current_period_end' => $remote->currentPeriodEnd,
            'ends_at' => $remote->endedAt,
        ]);

        $this->entitlements->forget((int) $subscription->user_id);

        return $drifted;
    }

    /* ------------------------------------------------------------------ */
    /* State transitions                                                   */
    /* ------------------------------------------------------------------ */

    private function activate(Subscription $subscription, WebhookEvent $event): void
    {
        /*
         * Retire every OTHER non-terminal row for this user — the trial they
         * were on, or a checkout they abandoned. Leaving one alive breaks the
         * "at most one non-terminal subscription" invariant that entitlement
         * resolution depends on.
         */
        Subscription::query()
            ->where('user_id', $subscription->user_id)
            ->whereKeyNot($subscription->getKey())
            ->nonTerminal()
            ->update(['status' => SubscriptionStatus::Expired]);

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => $event->currentPeriodStart,
            'current_period_end' => $event->currentPeriodEnd,
            'failed_payment_count' => 0,
            'grace_ends_at' => null,
            'mandate_method' => $event->method,
            'last_payment_at' => CarbonImmutable::now(),
        ]);
    }

    private function renew(Subscription $subscription, WebhookEvent $event): void
    {
        /*
         * Idempotency guard: if the period we are being told about is one we
         * have already recorded, this is a replay. Extending again would give
         * the customer a free month per duplicate delivery.
         */
        if ($event->currentPeriodEnd !== null
            && $subscription->current_period_end !== null
            && $this->sameMoment($subscription->current_period_end, $event->currentPeriodEnd)) {
            return;
        }

        $changes = [
            'status' => SubscriptionStatus::Active,
            'current_period_start' => $event->currentPeriodStart,
            'current_period_end' => $event->currentPeriodEnd,
            'failed_payment_count' => 0,
            'grace_ends_at' => null,
            'last_payment_at' => CarbonImmutable::now(),
        ];

        // A downgrade scheduled for the cycle end lands HERE, on the first
        // renewal after it was requested. Applying it any earlier would cut the
        // account cap while the user was still paying the higher rate.
        $metadata = (array) $subscription->metadata;

        if (isset($metadata['pending_plan_code'])) {
            $pending = Plan::query()->where('code', $metadata['pending_plan_code'])->first();

            if ($pending instanceof Plan) {
                $changes['plan_id'] = $pending->id;
                $changes['plan_price_id'] = PlanPrice::query()
                    ->where('plan_id', $pending->id)
                    ->where('gateway', $subscription->gateway)
                    ->where('is_active', true)
                    ->value('id');
            }

            unset($metadata['pending_plan_code']);
            $changes['metadata'] = $metadata;
        }

        $subscription->update($changes);
    }

    private function markPastDue(Subscription $subscription, WebhookEvent $event): void
    {
        $graceDays = (int) config('razorpay.grace_days', 7);

        $subscription->update([
            'status' => SubscriptionStatus::PastDue,
            'failed_payment_count' => (int) $subscription->failed_payment_count + 1,
            // Set on the FIRST failure only — retries must not keep pushing the
            // deadline out, or a permanently failing card never locks.
            'grace_ends_at' => $subscription->grace_ends_at
                ?? CarbonImmutable::now()->addDays($graceDays),
        ]);
    }

    private function cancelLocal(Subscription $subscription): void
    {
        $subscription->update([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::now(),
        ]);
    }

    private function setStatus(Subscription $subscription, SubscriptionStatus $status): void
    {
        $subscription->update(['status' => $status]);
    }

    /**
     * The user killed the mandate at their bank or in their UPI app.
     *
     * No further charge is possible, so this is a cancellation whatever the
     * subscription currently says. Missing this leaves them `active` forever
     * while paying nothing.
     */
    private function revokeMandate(Subscription $subscription): void
    {
        $subscription->update([
            'mandate_revoked_at' => CarbonImmutable::now(),
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::now(),
            'cancel_at_period_end' => true,
        ]);

        // Told explicitly, because the cancellation happened somewhere this
        // product cannot see. Without this the first the user hears of it is
        // their account locking a month later.
        $subscription->user?->notify(new MandateRevokedNotice($subscription));
    }

    private function recordPayment(Subscription $subscription, WebhookEvent $event): void
    {
        if ($event->gatewayPaymentId === null) {
            return;
        }

        $status = match ($event->type) {
            WebhookEventType::PaymentFailed, WebhookEventType::SubscriptionPaymentFailed => PaymentStatus::Failed,
            WebhookEventType::PaymentRefunded => PaymentStatus::Refunded,
            default => PaymentStatus::Captured,
        };

        // updateOrCreate on the gateway payment id — the same payment arrives
        // on both `payment.captured` and `subscription.charged`.
        $payment = Payment::updateOrCreate(
            ['gateway_payment_id' => $event->gatewayPaymentId],
            [
                'user_id' => $subscription->user_id,
                'subscription_id' => $subscription->id,
                'gateway' => $event->gateway,
                'amount' => $event->amount ?? Money::ZERO,
                'currency_code' => $event->currencyCode ?? 'INR',
                'status' => $status,
                'method' => $event->method,
                'failure_code' => $event->failureCode,
                'failure_message' => $event->failureMessage,
                'paid_at' => $status === PaymentStatus::Captured ? CarbonImmutable::now() : null,
            ],
        );

        if ($status === PaymentStatus::Captured) {
            /*
             * afterCommit, so the worker can never read a payment row that the
             * surrounding transaction has not written yet. IssueInvoiceJob is
             * itself idempotent on payment_id, so a duplicate dispatch from a
             * replayed webhook returns the existing invoice rather than burning
             * a second number out of the tax series.
             */
            IssueInvoiceJob::dispatch((int) $payment->id)->afterCommit();
        }

        if ($status === PaymentStatus::Failed) {
            $subscription->user?->notify(new PaymentFailedNotice($subscription, $event->failureMessage));
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * The one subscription that currently governs a user's access.
     *
     * Same rule EntitlementService uses — newest non-terminal row — so the two
     * can never disagree about which subscription is in charge.
     */
    public function currentFor(int $userId): ?Subscription
    {
        return Subscription::query()
            ->with('plan')
            ->where('user_id', $userId)
            ->nonTerminal()
            ->latest('id')
            ->first();
    }

    private function locateSubscription(WebhookEvent $event): ?Subscription
    {
        if ($event->gatewaySubscriptionId === null) {
            return null;
        }

        return Subscription::query()
            ->where('gateway', $event->gateway)
            ->where('gateway_subscription_id', $event->gatewaySubscriptionId)
            ->first();
    }

    /**
     * A Pro user with 35 accounts cannot silently land on Basic (20).
     *
     * The exception carries the empty, unused accounts so the client can offer
     * a two-tap cleanup rather than leaving the user to work it out.
     */
    private function guardDowngrade(User $user, Plan $newPlan): void
    {
        $current = Account::query()->where('user_id', $user->id)->count();

        if ($current <= (int) $newPlan->max_accounts) {
            return;
        }

        $suggested = Account::query()
            ->where('user_id', $user->id)
            ->where('is_main', false)
            ->where('current_balance', '0.0000')
            ->orderBy('sort_order')
            ->limit($current - (int) $newPlan->max_accounts)
            ->get()
            ->map(fn (Account $a): array => [
                'uuid' => (string) $a->uuid,
                'name' => (string) $a->name,
                'balance' => (string) $a->current_balance,
            ])
            ->all();

        throw new DowngradeBlockedException($current, (int) $newPlan->max_accounts, $suggested);
    }

    private function sameMoment(mixed $a, CarbonImmutable $b): bool
    {
        $left = $a instanceof \DateTimeInterface
            ? CarbonImmutable::instance($a)
            : CarbonImmutable::createFromTimestamp((int) $a);

        return $left->equalTo($b);
    }
}
