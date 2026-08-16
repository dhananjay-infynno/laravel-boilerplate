<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/**
 * An inactive account cannot be transacted on. It stays visible in reports —
 * deactivating is not deleting.
 */
final class AccountInactiveException extends DomainException
{
    public function __construct(private readonly string $accountUuid = '')
    {
        parent::__construct((string) __('errors.account_inactive'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::AccountInactive;
    }

    public function meta(): array
    {
        return $this->accountUuid !== '' ? ['account_uuid' => $this->accountUuid] : [];
    }
}
