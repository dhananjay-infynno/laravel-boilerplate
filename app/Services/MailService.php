<?php

namespace App\Services;

use Exception;
use Throwable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailService
{
    /**
     * Send an email using the queue.
     */
    public function sendToQueue(string|array $to, Mailable $mailable, array $additionalBcc = [], bool $doNotAttachBcc = false): void
    {
        try {
            if (empty($to)) {
                Log::error('sendToQueue called without a recipient for mailable: ' . get_class($mailable));

                return;
            }

            $filteredTo = $this->filterEnabledRecipients($to);

            if ($filteredTo === null || (is_array($filteredTo) && empty($filteredTo))) {
                return;
            }

            $bcc = $this->buildBccList($additionalBcc);

            $mail = Mail::to($filteredTo);

            if (! empty($bcc) && ! $doNotAttachBcc) {
                $mail->bcc($bcc);
            }

            // send via queue on "mail" queue with a small random delay
            $mailable->onQueue('mail');
            $mail->later(now()->addMilliseconds(rand(100, 1000)), $mailable);
        } catch (Throwable $th) {
            Log::error('Throwable caught in MailService::sendToQueue: ' . $th->getMessage(), [
                'trace' => $th->getTraceAsString(),
            ]);

            report($th);
        } catch (Exception $e) {
            Log::error('Exception caught in MailService::sendToQueue: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            report($e);
        }
    }

    /**
     * Send an email immediately (without queue).
     */
    public function sendNow(string|array $to, Mailable $mailable, array $additionalBcc = [], bool $doNotAttachBcc = false): void
    {
        try {
            if (empty($to)) {
                Log::error('sendNow called without a recipient for mailable: ' . get_class($mailable));

                return;
            }

            $filteredTo = $this->filterEnabledRecipients($to);

            if ($filteredTo === null || (is_array($filteredTo) && empty($filteredTo))) {
                return;
            }

            $bcc = $this->buildBccList($additionalBcc);

            $mail = Mail::to($filteredTo);

            if (! empty($bcc) && ! $doNotAttachBcc) {
                $mail->bcc($bcc);
            }

            $mail->send($mailable);
        } catch (Throwable $th) {
            Log::error('Throwable caught in MailService::sendNow: ' . $th->getMessage(), [
                'trace' => $th->getTraceAsString(),
            ]);

            report($th);
        } catch (Exception $e) {
            Log::error('Exception caught in MailService::sendNow: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            report($e);
        }
    }

    /**
     * Placeholder for filtering recipients based on notification preferences.
     */
    protected function filterEnabledRecipients(string|array $to): string|array|null
    {
        // In this boilerplate we don't yet have per-user email preference flags,
        // so just return the input unchanged. You can extend this later.
        return $to;
    }

    /**
     * Build BCC list using global config plus any additional addresses.
     */
    protected function buildBccList(array $additionalBcc = []): array
    {
        if (! app()->environment('prod')) {
            return [];
        }

        $generalBccList = config('app.mail_bcc')
            ? array_filter(array_map('trim', explode(',', config('app.mail_bcc'))))
            : [];

        if (! empty($additionalBcc)) {
            $merged = array_merge($generalBccList, $additionalBcc);

            return array_values(array_unique($merged));
        }

        return $generalBccList;
    }
}
