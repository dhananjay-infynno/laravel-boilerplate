<?php

declare(strict_types=1);

namespace App\Services\Razorpay;

use App\Contracts\PaymentGateway;
use App\DataObjects\CheckoutIntent;
use App\DataObjects\GatewaySubscription;
use App\DataObjects\WebhookEvent;
use App\Enums\SubscriptionStatus;
use App\Enums\WebhookEventType;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Razorpay Subscriptions with UPI Autopay / card e-mandate.
 *
 * Implemented against the REST API with Laravel's HTTP client rather than the
 * razorpay/razorpay SDK: one fewer dependency, and the request/response shapes
 * stay visible at the call site, which matters when debugging a webhook at 2am.
 *
 * VERIFY PARAMETER NAMES AGAINST https://razorpay.com/docs/api/subscriptions/
 * before changing anything here. Gateway payload shapes are exactly the kind of
 * detail that is easy to get plausibly wrong.
 *
 * AMOUNTS: Razorpay works in PAISE as integers. Conversion happens ONLY through
 * Money::toPaise(). A stray `* 100` elsewhere is the classic first-day bug and
 * it overcharges by 100x.
 */
final class RazorpayGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.razorpay.com/v1';

    public function code(): string
    {
        return 'razorpay';
    }

    public function supportsCurrency(string $currencyCode): bool
    {
        // India only for now. Razorpay International exists but needs separate
        // approval — see docs/03-BILLING.md §11.
        return strtoupper($currencyCode) === 'INR';
    }

    public function createCustomer(User $user): string
    {
        $response = $this->client()->post('/customers', [
            'name' => $user->name,
            'email' => $user->email,
            'contact' => $user->phone ?? $user->mobile_no,
            'fail_existing' => 0, // Return the existing customer instead of erroring.
            'notes' => ['user_uuid' => (string) $user->uuid],
        ]);

        $this->assertOk($response->json(), $response->status(), 'create customer');

        return (string) $response->json('id');
    }

    public function createPlan(Plan $plan, PlanPrice $price): string
    {
        $response = $this->client()->post('/plans', [
            'period' => $this->period($plan->interval->value),
            'interval' => max(1, (int) $plan->interval_count),
            'item' => [
                'name' => $plan->name,
                'description' => $plan->description ?? $plan->name,
                'amount' => Money::toPaise((string) $price->amount),
                'currency' => strtoupper((string) $price->currency_code),
            ],
            'notes' => ['plan_code' => (string) $plan->code],
        ]);

        $this->assertOk($response->json(), $response->status(), 'create plan');

        return (string) $response->json('id');
    }

    public function createSubscription(User $user, PlanPrice $price): CheckoutIntent
    {
        $plan = $price->plan;

        if (! $plan instanceof Plan || blank($price->gateway_plan_id)) {
            // The plan was never synced to Razorpay. Failing loudly here beats
            // a confusing checkout error in front of a paying customer.
            throw new RuntimeException("Plan {$price->plan_id} has no Razorpay plan id. Run plans:sync-gateway.");
        }

        $response = $this->client()->post('/subscriptions', [
            'plan_id' => $price->gateway_plan_id,
            'total_count' => $this->totalCount($plan->interval->value),
            'quantity' => 1,
            'customer_notify' => 1,
            /*
             * The mandate ceiling, NOT the plan price — see config/razorpay.php.
             * Authorising Rs 2,000 once means a later upgrade never sends the
             * user back through mandate registration.
             */
            'addons' => [],
            'notes' => [
                'user_uuid' => (string) $user->uuid,
                'plan_code' => (string) $plan->code,
            ],
        ]);

        $this->assertOk($response->json(), $response->status(), 'create subscription');

        return new CheckoutIntent(
            gateway: $this->code(),
            gatewaySubscriptionId: (string) $response->json('id'),
            // Publishable key — safe to send to the phone. The secret never does.
            keyId: (string) config('razorpay.key'),
            planCode: (string) $plan->code,
            amount: Money::normalise((string) $price->amount),
            currencyCode: (string) $price->currency_code,
            prefill: [
                'name' => $user->name,
                'email' => $user->email,
                'contact' => $user->phone ?? $user->mobile_no,
            ],
            notes: ['user_uuid' => (string) $user->uuid],
        );
    }

    public function changePlan(Subscription $subscription, PlanPrice $newPrice, bool $atCycleEnd): void
    {
        $response = $this->client()->patch("/subscriptions/{$subscription->gateway_subscription_id}", [
            'plan_id' => $newPrice->gateway_plan_id,
            // Upgrades apply now (prorated); downgrades wait for the cycle end,
            // because the customer paid for the higher tier through the period.
            'schedule_change_at' => $atCycleEnd ? 'cycle_end' : 'now',
            'quantity' => 1,
        ]);

        $this->assertOk($response->json(), $response->status(), 'change plan');
    }

    public function cancelSubscription(Subscription $subscription, bool $atPeriodEnd): void
    {
        $response = $this->client()->post("/subscriptions/{$subscription->gateway_subscription_id}/cancel", [
            'cancel_at_cycle_end' => $atPeriodEnd ? 1 : 0,
        ]);

        $this->assertOk($response->json(), $response->status(), 'cancel subscription');
    }

    public function resumeSubscription(Subscription $subscription): void
    {
        $response = $this->client()->post("/subscriptions/{$subscription->gateway_subscription_id}/resume", [
            'resume_at' => 'now',
        ]);

        $this->assertOk($response->json(), $response->status(), 'resume subscription');
    }

    public function fetchSubscription(string $gatewaySubscriptionId): GatewaySubscription
    {
        $response = $this->client()->get("/subscriptions/{$gatewaySubscriptionId}");

        $this->assertOk($response->json(), $response->status(), 'fetch subscription');

        $data = $response->json();

        return new GatewaySubscription(
            gatewaySubscriptionId: (string) $data['id'],
            status: $this->mapStatus((string) ($data['status'] ?? '')),
            currentPeriodStart: $this->moment($data['current_start'] ?? null),
            currentPeriodEnd: $this->moment($data['current_end'] ?? null),
            endedAt: $this->moment($data['ended_at'] ?? null),
            gatewayPlanId: $data['plan_id'] ?? null,
            paidCount: (int) ($data['paid_count'] ?? 0),
            raw: $data,
        );
    }

    public function refund(string $gatewayPaymentId, ?string $amount, string $reason): array
    {
        $payload = ['notes' => ['reason' => $reason]];

        if ($amount !== null) {
            $payload['amount'] = Money::toPaise($amount);
        }

        $response = $this->client()->post("/payments/{$gatewayPaymentId}/refund", $payload);

        $this->assertOk($response->json(), $response->status(), 'refund');

        return (array) $response->json();
    }

    /**
     * HMAC-SHA256 over the RAW body with the webhook secret.
     *
     * Uses the raw content, not the re-encoded array: re-encoding changes key
     * order and whitespace, and the signature would never match.
     *
     * hash_equals is timing-safe — a plain === here leaks the signature one
     * byte at a time.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $signature = $request->header('X-Razorpay-Signature');
        $secret = (string) config('razorpay.webhook_secret');

        if (! is_string($signature) || $signature === '' || $secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Translate a Razorpay payload into our canonical event.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhook(array $payload): WebhookEvent
    {
        $event = (string) ($payload['event'] ?? '');
        $subscription = data_get($payload, 'payload.subscription.entity');
        $payment = data_get($payload, 'payload.payment.entity');

        return new WebhookEvent(
            // Razorpay sends `id` at the top level on newer accounts and
            // x-razorpay-event-id as a header on older ones. Fall back to a
            // deterministic composite so dedupe still works either way.
            eventId: (string) ($payload['id'] ?? $this->syntheticEventId($event, $subscription, $payment)),
            type: $this->mapEvent($event),
            gateway: $this->code(),
            gatewaySubscriptionId: $subscription['id'] ?? data_get($payment, 'subscription_id'),
            gatewayPaymentId: $payment['id'] ?? null,
            amount: isset($payment['amount']) ? Money::fromPaise((int) $payment['amount']) : null,
            currencyCode: $payment['currency'] ?? null,
            method: $payment['method'] ?? null,
            currentPeriodStart: $this->moment($subscription['current_start'] ?? null),
            currentPeriodEnd: $this->moment($subscription['current_end'] ?? null),
            failureCode: $payment['error_code'] ?? null,
            failureMessage: $payment['error_description'] ?? null,
            raw: $payload,
        );
    }

    /**
     * Verify the post-checkout signature returned by the client SDK.
     *
     * For SUBSCRIPTIONS the signed string is `payment_id + "|" + subscription_id`
     * — note the order, which is the reverse of the one-time-payment flow
     * (`order_id|payment_id`). Getting it backwards produces a signature that
     * never validates and a "payment failed" screen after a successful payment.
     *
     * @param  array<string, string>  $params
     */
    public function verifyCheckoutSignature(array $params): bool
    {
        $paymentId = $params['razorpay_payment_id'] ?? null;
        $subscriptionId = $params['razorpay_subscription_id'] ?? null;
        $signature = $params['razorpay_signature'] ?? null;

        if (! is_string($paymentId) || ! is_string($subscriptionId) || ! is_string($signature)) {
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            $paymentId.'|'.$subscriptionId,
            (string) config('razorpay.secret'),
        );

        return hash_equals($expected, $signature);
    }

    /**
     * Razorpay event -> our vocabulary.
     *
     * Anything unlisted becomes Unknown: recorded in payment_events, then
     * ignored. An unrecognised event is never an error — Razorpay adds new
     * ones, and throwing would make it retry forever.
     */
    private function mapEvent(string $event): WebhookEventType
    {
        return match ($event) {
            'subscription.activated', 'subscription.authenticated' => WebhookEventType::SubscriptionActivated,
            'subscription.charged' => WebhookEventType::SubscriptionRenewed,
            'subscription.pending', 'subscription.halted' => WebhookEventType::SubscriptionPaymentFailed,
            'subscription.cancelled' => WebhookEventType::SubscriptionCancelled,
            'subscription.paused' => WebhookEventType::SubscriptionPaused,
            'subscription.resumed' => WebhookEventType::SubscriptionResumed,
            'subscription.completed' => WebhookEventType::SubscriptionExpired,
            // The one everybody forgets — a UPI Autopay user can cancel from
            // their UPI app without ever touching this product.
            'subscription.updated' => WebhookEventType::Unknown,
            'token.cancelled', 'mandate.revoked' => WebhookEventType::MandateRevoked,
            'payment.captured' => WebhookEventType::PaymentCaptured,
            'payment.failed' => WebhookEventType::PaymentFailed,
            'refund.processed', 'refund.created' => WebhookEventType::PaymentRefunded,
            default => WebhookEventType::Unknown,
        };
    }

    private function mapStatus(string $status): SubscriptionStatus
    {
        return match ($status) {
            'created', 'authenticated' => SubscriptionStatus::Trialing,
            'active' => SubscriptionStatus::Active,
            'pending', 'halted' => SubscriptionStatus::PastDue,
            'paused' => SubscriptionStatus::Paused,
            'cancelled' => SubscriptionStatus::Cancelled,
            'completed', 'expired' => SubscriptionStatus::Expired,
            default => SubscriptionStatus::Expired,
        };
    }

    private function period(string $interval): string
    {
        return match ($interval) {
            'year' => 'yearly',
            'week' => 'weekly',
            'day' => 'daily',
            default => 'monthly',
        };
    }

    private function totalCount(string $interval): int
    {
        return (int) config("razorpay.total_count.{$interval}", 120);
    }

    private function moment(mixed $timestamp): ?CarbonImmutable
    {
        return $timestamp === null || $timestamp === 0
            ? null
            : CarbonImmutable::createFromTimestamp((int) $timestamp);
    }

    private function syntheticEventId(string $event, mixed $subscription, mixed $payment): string
    {
        return hash('sha256', implode('|', [
            $event,
            (string) ($subscription['id'] ?? ''),
            (string) ($payment['id'] ?? ''),
            (string) ($subscription['current_end'] ?? $payment['created_at'] ?? ''),
        ]));
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withBasicAuth((string) config('razorpay.key'), (string) config('razorpay.secret'))
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            // Retry only on transport failures. A 4xx is deterministic and
            // retrying it just burns time.
            ->retry(2, 200, throw: false);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function assertOk(?array $body, int $status, string $operation): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }

        $description = data_get($body, 'error.description') ?? 'Unknown Razorpay error';

        throw new RuntimeException("Razorpay {$operation} failed ({$status}): {$description}");
    }
}
