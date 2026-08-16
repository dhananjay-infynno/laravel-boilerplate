<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/**
 * The OTP was correct but past its expiry window.
 *
 * Distinct from InvalidOtp on purpose: the code was genuinely issued to this
 * address, so there is no enumeration risk, and "expired" tells the user to
 * resend rather than to re-check what they typed.
 */
final class OtpExpiredException extends DomainException
{
    public function __construct()
    {
        parent::__construct((string) __('errors.otp_expired'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::OtpExpired;
    }
}
