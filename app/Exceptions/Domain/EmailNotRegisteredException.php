<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/**
 * Only used where confirming the address is already safe — never on login or
 * OTP verification, which must stay enumeration-proof.
 */
final class EmailNotRegisteredException extends DomainException
{
    public function __construct()
    {
        parent::__construct((string) __('errors.email_not_registered'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::EmailNotRegistered;
    }
}
