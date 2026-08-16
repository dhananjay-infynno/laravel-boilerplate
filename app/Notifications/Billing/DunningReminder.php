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
 * Escalating reminders while a subscription sits past_due.
 *
 * Three stages across the grace window, escalating in urgency but never in
 * hostility. The final one names the exact date access pauses, because a
 * deadline someone can act on converts and a vague threat does not.
 */
final class DunningReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'second'|'final'|'suspended'  $stage
     */
    public function __construct(
        private readonly Subscription $subscription,
        private readonly string $stage,
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
        $graceEnds = $this->subscription->grace_ends_at;
        $date = $graceEnds === null
            ? null
            : CarbonImmutable::createFromTimestamp((int) $graceEnds)->format('j M Y');

        $days = $graceEnds === null
            ? 0
            : max(0, CarbonImmutable::now()->diffInDays(CarbonImmutable::createFromTimestamp((int) $graceEnds), false));

        return (new MailMessage)
            ->subject((string) __("billing.dunning.{$this->stage}.subject", ['days' => (int) $days]))
            ->greeting((string) __('email.greeting', ['name' => $notifiable->name ?: __('email.there')]))
            ->line((string) __("billing.dunning.{$this->stage}.line", [
                'days' => (int) $days,
                'date' => $date ?? '',
            ]))
            ->action((string) __('billing.dunning.action'), url('/billing'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => "dunning_{$this->stage}",
            'subscription_uuid' => (string) $this->subscription->uuid,
            'grace_ends_at' => $this->subscription->grace_ends_at,
        ];
    }
}
