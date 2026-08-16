<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/**
 * Wrong email or wrong password — deliberately indistinguishable.
 *
 * Saying which one was wrong turns login into an account-enumeration oracle.
 * Also thrown when re-entering the account password to change a PIN.
 */
final class InvalidCredentialsException extends DomainException
{
    public function __construct()
    {
        parent::__construct((string) __('errors.invalid_credentials'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InvalidCredentials;
    }
}
