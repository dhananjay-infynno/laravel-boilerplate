<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class TrialEndingSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $daysLeft,
        private readonly CarbonImmutable $endsAt,
    ) {
        $this->onQueue('mail');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Push as well as email: this is the one billing message where a
        // notification the user actually sees prevents a support ticket.
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject((string) __('billing.trial.subject', ['days' => $this->daysLeft]))
            ->greeting((string) __('email.greeting', ['name' => $notifiable->name ?: __('email.there')]))
            ->line((string) __('billing.trial.line', [
                'days' => $this->daysLeft,
                'date' => $this->endsAt->format('j M Y'),
            ]))
            // Reassurance is the point: the fear is losing the books, not
            // losing the feature.
            ->line((string) __('billing.trial.reassurance'))
            ->action((string) __('billing.trial.action'), url('/billing'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'trial_ending',
            'days_left' => $this->daysLeft,
            'ends_at' => $this->endsAt->toIso8601String(),
        ];
    }
}
