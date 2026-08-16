<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/**
 * Every user must always have exactly one main account. To remove this one,
 * promote another first.
 */
final class CannotDeleteMainAccountException extends DomainException
{
    public function __construct()
    {
        parent::__construct((string) __('errors.cannot_delete_main_account'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CannotDeleteMainAccount;
    }
}
