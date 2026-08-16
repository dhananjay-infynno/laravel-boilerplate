<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErrorCode;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CancelSubscription;
use App\Http\Requests\Billing\ChangePlan;
use App\Http\Requests\Billing\Checkout;
use App\Http\Requests\Billing\VerifyCheckout;
use App\Http\Resources\Billing\InvoiceResource;
use App\Http\Resources\Billing\PaymentResource;
use App\Http\Resources\Billing\PlanResource;
use App\Http\Resources\Billing\SubscriptionResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\PaymentGatewayManager;
use App\Services\SubscriptionService;
use App\Traits\ApiResponser;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Billing.
 *
 * None of these routes sit behind `can.write`: an expired user MUST be able to
 * reach checkout, and gating the way out of the paywall behind the paywall is a
 * dead end nobody notices until a customer emails about it.
 *
 * @tags Billing
 */
#[Group('Billing', weight: 60)]
final class SubscriptionController extends Controller
{
    use ApiResponser;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    /**
     * List selectable plans.
     *
     * Public — the paywall renders before a user has decided anything, and
     * pricing is not a secret.
     */
    public function plans(): JsonResponse
    {
        $plans = Plan::query()
            ->selectable()
            ->with(['prices' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->get();

        return $this->collection(PlanResource::collection($plans));
    }

    /**
     * The caller's current subscription.
     *
     * 200 with `data: null` rather than 404 — "you have no subscription" is a
     * valid state the paywall needs to render, not an error.
     */
    public function current(): JsonResponse
    {
        $subscription = $this->activeSubscription();

        return $subscription instanceof Subscription
            ? $this->resource(new SubscriptionResource($subscription->load('plan')))
            : $this->success(null, (string) __('billing.no_subscription'));
    }

    /**
     * Open checkout.
     *
     * Returns everything the client SDK needs. Nothing is granted here — the
     * subscription row is created inactive and waits for the webhook.
     */
    public function checkout(Checkout $request): JsonResponse
    {
        $intent = $this->subscriptions->checkout(
            $request->user(),
            (string) $request->validated('plan_code'),
        );

        return $this->success($intent->toArray(), (string) __('billing.checkout_created'), 201);
    }

    /**
     * Confirm the client-side handshake.
     *
     * READ THIS BEFORE CHANGING IT.
     *
     * A valid signature here proves the client is not fabricating a payment. It
     * does NOT prove money moved, and this endpoint therefore grants NOTHING.
     * Entitlements are set by the webhook and only by the webhook.
     *
     * The response deliberately reports the subscription's CURRENT status,
     * which is usually still `trialing` for a second or two — the app shows
     * "activating..." and re-fetches. Flipping it to active here to make the UI
     * feel snappier is exactly how a product ends up giving away subscriptions
     * to anyone who can POST a forged callback.
     */
    public function verify(VerifyCheckout $request): JsonResponse
    {
        /** @var array<string, string> $params */
        $params = $request->validated();

        $valid = $this->gateways->driver('razorpay')->verifyCheckoutSignature($params);

        if (! $valid) {
            return $this->error(
                (string) __('billing.verification_failed'),
                ErrorCode::ValidationFailed,
                422,
            );
        }

        $subscription = Subscription::query()
            ->where('user_id', Auth::id())
            ->where('gateway_subscription_id', $params['razorpay_subscription_id'])
            ->first();

        return $subscription instanceof Subscription
            ? $this->resource(new SubscriptionResource($subscription->load('plan')), (string) __('billing.verified'))
            : $this->success(null, (string) __('billing.verified'));
    }

    /**
     * Change plan. Direction decides timing — see ChangePlan.
     */
    public function changePlan(ChangePlan $request): JsonResponse
    {
        $subscription = $this->activeSubscription();

        if (! $subscription instanceof Subscription) {
            return $this->error((string) __('billing.no_subscription'), ErrorCode::NotFound, 404);
        }

        $updated = $this->subscriptions->changePlan(
            $subscription,
            (string) $request->validated('plan_code'),
        );

        return $this->resource(new SubscriptionResource($updated->load('plan')), (string) __('billing.plan_changed'));
    }

    /**
     * Cancel. Defaults to end-of-period.
     */
    public function cancel(CancelSubscription $request): JsonResponse
    {
        $subscription = $this->activeSubscription();

        if (! $subscription instanceof Subscription) {
            return $this->error((string) __('billing.no_subscription'), ErrorCode::NotFound, 404);
        }

        $updated = $this->subscriptions->cancel($subscription, $request->boolean('at_period_end', true));

        return $this->resource(new SubscriptionResource($updated->load('plan')), (string) __('billing.cancelled'));
    }

    /**
     * Undo a pending cancellation.
     *
     * Only valid while the period has not lapsed — after that the mandate is
     * gone and the user has to go through checkout again.
     */
    public function resume(): JsonResponse
    {
        $subscription = Subscription::query()
            ->where('user_id', Auth::id())
            ->where('status', SubscriptionStatus::Cancelled)
            ->latest('id')
            ->first();

        if (! $subscription instanceof Subscription) {
            return $this->error((string) __('billing.nothing_to_resume'), ErrorCode::NotFound, 404);
        }

        $updated = $this->subscriptions->resume($subscription);

        return $this->resource(new SubscriptionResource($updated->load('plan')), (string) __('billing.resumed'));
    }

    /**
     * Payment history.
     */
    public function payments(): JsonResponse
    {
        $payments = Payment::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('id')
            ->paginate(20);

        return $this->collection(PaymentResource::collection($payments));
    }

    /**
     * Invoice history.
     */
    public function invoices(): JsonResponse
    {
        $invoices = Invoice::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('id')
            ->paginate(20);

        return $this->collection(InvoiceResource::collection($invoices));
    }

    /**
     * Download an invoice PDF.
     *
     * Scoped by user_id in the query, not by an authorize() call after the
     * fact — an IDOR on a tax document leaks the customer's name, address and
     * GSTIN.
     */
    public function downloadInvoice(string $invoice): StreamedResponse|JsonResponse
    {
        $record = Invoice::query()
            ->where('user_id', Auth::id())
            ->where('uuid', $invoice)
            ->first();

        if (! $record instanceof Invoice || $record->pdf_path === null) {
            return $this->error((string) __('billing.invoice_not_found'), ErrorCode::NotFound, 404);
        }

        return response()->streamDownload(
            function () use ($record): void {
                echo Storage::disk('local')->get((string) $record->pdf_path);
            },
            "invoice-{$record->invoice_number}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * The one subscription that currently governs access.
     *
     * `cancelled` is included on purpose: entitlements survive to
     * current_period_end, so it is still the row that matters.
     */
    private function activeSubscription(): ?Subscription
    {
        // Delegated so this and EntitlementService can never disagree about
        // which subscription is in charge.
        return $this->subscriptions->currentFor((int) Auth::id());
    }
}
