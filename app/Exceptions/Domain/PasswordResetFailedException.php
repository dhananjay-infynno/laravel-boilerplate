<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/** The reset token was invalid or expired. */
final class PasswordResetFailedException extends DomainException
{
    public function __construct(private readonly string $reason = '')
    {
        parent::__construct((string) __('errors.password_reset_failed'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PasswordResetFailed;
    }

    public function meta(): array
    {
        // Laravel's status string ("passwords.token") is internal detail, so it
        // is logged rather than returned.
        return [];
    }
}
