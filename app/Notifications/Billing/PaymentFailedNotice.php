<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The FIRST failure only. Subsequent nudges come from the dunning command, so
 * a gateway retrying four times in an hour does not send four emails.
 *
 * Tone is deliberately non-accusatory: in this market a failed UPI mandate is
 * usually a bank issue, not a customer refusing to pay, and an aggressive first
 * email costs more in churn than the invoice is worth.
 */
final class PaymentFailedNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Subscription $subscription,
        private readonly ?string $reason = null,
    ) {
        $this->onQueue('mail');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject((string) __('billing.dunning.first.subject'))
            ->greeting((string) __('email.greeting', ['name' => $notifiable->name ?: __('email.there')]))
            ->line((string) __('billing.dunning.first.line'));

        // Surfaced only when the gateway gave something a human can act on
        // ("insufficient funds"). A raw error code helps nobody.
        if ($this->reason !== null && $this->reason !== '') {
            $message->line((string) __('billing.dunning.reason', ['reason' => $this->reason]));
        }

        return $message->action((string) __('billing.dunning.action'), url('/billing'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment_failed',
            'subscription_uuid' => (string) $this->subscription->uuid,
            'grace_ends_at' => $this->subscription->grace_ends_at,
        ];
    }
}
