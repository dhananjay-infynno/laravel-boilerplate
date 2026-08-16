<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DataObjects\CheckoutIntent;
use App\DataObjects\GatewaySubscription;
use App\DataObjects\WebhookEvent;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The seam between the billing domain and any payment provider.
 *
 * `RazorpayGateway` is the only implementation today, and the product is India
 * only. This interface exists anyway because it costs almost nothing now and is
 * what makes going international a new class rather than a rewrite —
 * `docs/03-BILLING.md` §11.
 *
 * It matters more than usual here: an Indian-registered entity generally cannot
 * use Stripe for international billing, so the eventual second implementation is
 * likely Paddle or Lemon Squeezy as merchant of record. Nothing in
 * SubscriptionService should ever import a Razorpay class.
 */
interface PaymentGateway
{
    /** 'razorpay' — matches `plan_prices.gateway` and `subscriptions.gateway`. */
    public function code(): string;

    public function supportsCurrency(string $currencyCode): bool;

    /** Returns the gateway's customer id, creating one if needed. */
    public function createCustomer(User $user): string;

    /** Creates the plan on the gateway; returns its id for `plan_prices`. */
    public function createPlan(Plan $plan, PlanPrice $price): string;

    /** Everything the client needs to open checkout. */
    public function createSubscription(User $user, PlanPrice $price): CheckoutIntent;

    public function changePlan(Subscription $subscription, PlanPrice $newPrice, bool $atCycleEnd): void;

    public function cancelSubscription(Subscription $subscription, bool $atPeriodEnd): void;

    public function resumeSubscription(Subscription $subscription): void;

    /** The gateway's view of a subscription — used by the reconcile jobs. */
    public function fetchSubscription(string $gatewaySubscriptionId): GatewaySubscription;

    /** @param  string|null  $amount  Null refunds in full. */
    public function refund(string $gatewayPaymentId, ?string $amount, string $reason): array;

    /**
     * Verify the webhook signature.
     *
     * MUST be called before anything else touches the payload. An unsigned
     * webhook endpoint is a free-subscription generator.
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * Translate a provider payload into ONE canonical internal event.
     *
     * This is what keeps SubscriptionService provider-agnostic: it only ever
     * sees our vocabulary, never Razorpay's.
     */
    public function parseWebhook(array $payload): WebhookEvent;

    /**
     * Verify the signature the CLIENT hands back after checkout closes.
     *
     * A true result means the client is not lying about having completed
     * checkout. It does NOT mean money moved, and it MUST NOT grant
     * entitlements — only a webhook does that. This exists so the app can show
     * "activating..." instead of polling blindly.
     *
     * @param  array<string, string>  $params
     */
    public function verifyCheckoutSignature(array $params): bool;
}
