<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/**
 * Wrong OTP — and also what an unknown email address gets.
 *
 * Telling the caller "no such account" would make the OTP endpoints an
 * enumeration oracle, so both failures are identical.
 */
final class InvalidOtpException extends DomainException
{
    public function __construct()
    {
        parent::__construct((string) __('errors.invalid_otp'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidOtp;
    }
}
