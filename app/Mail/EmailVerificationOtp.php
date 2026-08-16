<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The OTP that starts the 30-day trial.
 *
 * The single most delivery-critical mail in the product: a user who never
 * receives it never verifies, never starts a trial, and churns before seeing
 * the app at all. Warm the sending domain and set SPF, DKIM and DMARC before
 * launch (`docs/00-MASTER-PLAN.md` §7) or these land in spam and signups die
 * silently.
 */
class EmailVerificationOtp extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(private ?object $user, private $otp) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('email.email_verification.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.email-verification-otp',
            with: ['user' => $this->user, 'otp' => $this->otp, 'name' => $this->user->name],
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
