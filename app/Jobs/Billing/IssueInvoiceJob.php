<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Notifications\Billing\PaymentReceipt;
use App\Services\InvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Issues the GST invoice for a captured payment and emails the receipt.
 *
 * Queued rather than inline in the webhook handler: numbering takes a row lock
 * on `invoice_sequence`, which every concurrent payment contends for. Holding
 * that inside the webhook path would turn a burst of renewals into a queue of
 * timing-out HTTP requests and a retry storm.
 */
final class IssueInvoiceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $paymentId)
    {
        $this->onQueue('billing');
    }

    public function handle(InvoiceService $invoices): void
    {
        $payment = Payment::find($this->paymentId);

        if (! $payment instanceof Payment) {
            return;
        }

        // Only captured payments get a tax invoice. Issuing one for a failed or
        // merely authorised payment puts a number in the series for money that
        // never arrived — and the number cannot be reused.
        if ($payment->status !== PaymentStatus::Captured) {
            return;
        }

        $invoice = $invoices->issueFor($payment);

        $user = $payment->user()->first();

        if ($user === null) {
            Log::warning('Invoice issued for a payment with no user.', ['payment_id' => $payment->id]);

            return;
        }

        $user->notify(new PaymentReceipt($invoice));
    }
}
