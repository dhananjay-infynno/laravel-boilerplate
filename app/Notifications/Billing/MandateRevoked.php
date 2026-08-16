<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The auto-pay mandate was cancelled at the bank or in the user's UPI app.
 *
 * This one is easy to omit and expensive to omit. With UPI Autopay the
 * cancellation happens somewhere this product cannot see — the user never opens
 * the app, so without this email the first they learn of it is when their
 * account locks a month later, and it reads as the product breaking.
 *
 * It is also sent for the honest case: sometimes the bank revokes a mandate the
 * customer never touched.
 */
final class MandateRevoked extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Subscription $subscription)
    {
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
        $end = $this->subscription->current_period_end;
        $date = $end === null
            ? null
            : CarbonImmutable::createFromTimestamp((int) $end)->format('j M Y');

        return (new MailMessage)
            ->subject((string) __('billing.mandate_revoked.subject'))
            ->greeting((string) __('email.greeting', ['name' => $notifiable->name ?: __('email.there')]))
            // The date matters: access does NOT stop today. They paid through
            // the current period and they keep it.
            ->line((string) __('billing.mandate_revoked.line', ['date' => $date ?? '']))
            ->action((string) __('billing.mandate_revoked.action'), url('/billing'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'mandate_revoked',
            'subscription_uuid' => (string) $this->subscription->uuid,
            'current_period_end' => $this->subscription->current_period_end,
        ];
    }
}
