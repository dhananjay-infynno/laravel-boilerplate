<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PaymentReceipt extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Invoice $invoice)
    {
        $this->onQueue('mail');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Email only. A push notification saying "we took your money" is not
        // something anyone wants on their lock screen.
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $total = Money::format((string) $this->invoice->total_amount);

        return (new MailMessage)
            ->subject((string) __('billing.receipt.subject', ['number' => $this->invoice->invoice_number]))
            ->greeting((string) __('email.greeting', ['name' => $notifiable->name ?: __('email.there')]))
            ->line((string) __('billing.receipt.line'))
            ->line("Invoice: {$this->invoice->invoice_number}")
            ->line("Amount: ₹{$total}")
            ->action((string) __('billing.receipt.action'), url('/billing/invoices'));
    }
}
