<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Models\PaymentEvent;
use App\Services\PaymentGatewayManager;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies one stored webhook to the subscription state machine.
 *
 * Takes an ID, not the event object: the payload can be large, and serialising
 * it into the jobs table twice is waste. It also means a manual replay is just
 * `ProcessRazorpayWebhookJob::dispatch($id)`.
 *
 * Runs on the `billing` queue so a backlog of report exports can never delay a
 * payment confirmation.
 */
final class ProcessRazorpayWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 30;

    /** Long enough to outlive a transient outage, short enough to still alert. */
    public int $timeout = 60;

    public function __construct(public readonly int $paymentEventId)
    {
        $this->onQueue('billing');
    }

    /**
     * Serialise per SUBSCRIPTION, not globally.
     *
     * Two events for the same subscription arriving together (`payment.captured`
     * and `subscription.charged` are near-simultaneous) would otherwise
     * interleave read-modify-write on the same row. Different subscriptions
     * still run in parallel.
     */
    public function middleware(): array
    {
        $event = PaymentEvent::find($this->paymentEventId);
        $key = data_get($event?->payload, 'payload.subscription.entity.id')
            ?? data_get($event?->payload, 'payload.payment.entity.subscription_id')
            ?? "event:{$this->paymentEventId}";

        return [(new WithoutOverlapping("rzp:{$key}"))->releaseAfter(5)->expireAfter(120)];
    }

    public function handle(PaymentGatewayManager $gateways, SubscriptionService $subscriptions): void
    {
        $record = PaymentEvent::find($this->paymentEventId);

        if (! $record instanceof PaymentEvent) {
            return;
        }

        // Already applied. A retry after a timeout that actually succeeded
        // lands here, and re-applying would extend a period twice.
        if ($record->status === 'processed') {
            return;
        }

        $record->increment('attempts');

        try {
            $event = $gateways->driver('razorpay')->parseWebhook((array) $record->payload);

            $subscriptions->applyWebhook($event);

            $record->update([
                'status' => 'processed',
                'processed_at' => CarbonImmutable::now(),
                'error' => null,
            ]);
        } catch (Throwable $e) {
            $record->update([
                'status' => 'failed',
                // Truncated: a stack trace in a DB column is unreadable and the
                // full trace is in the log anyway.
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            Log::error('Razorpay webhook processing failed.', [
                'payment_event_id' => $record->id,
                'event_type' => $record->event_type,
                'attempt' => $record->attempts,
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    /**
     * Retries exhausted. The row stays `failed`, so the daily reconcile job
     * still repairs the subscription even though this event never applied —
     * which is the whole reason reconciliation exists.
     */
    public function failed(?Throwable $e): void
    {
        Log::critical('Razorpay webhook permanently failed — manual review required.', [
            'payment_event_id' => $this->paymentEventId,
            'exception' => $e?->getMessage(),
        ]);
    }
}
