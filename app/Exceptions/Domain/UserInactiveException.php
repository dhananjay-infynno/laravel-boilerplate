<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/** The account is inactive or suspended by an admin. */
final class UserInactiveException extends DomainException
{
    public function __construct()
    {
        parent::__construct((string) __('errors.user_inactive'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::UserInactive;
    }
}
