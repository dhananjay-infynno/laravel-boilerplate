<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/**
 * The write would take a balance below zero and the account does not allow
 * overdraft.
 *
 * Also thrown when accepting an external transfer whose sender has since spent
 * the money — which is why BOTH parties get notified when it fires.
 */
final class InsufficientBalanceException extends DomainException
{
    public function __construct(
        private readonly string $accountUuid,
        private readonly string $requested,
        private readonly string $available,
    ) {
        parent::__construct((string) __('errors.insufficient_balance'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::InsufficientBalance;
    }

    public function meta(): array
    {
        return [
            'account_uuid' => $this->accountUuid,
            'requested' => $this->requested,
            'available' => $this->available,
        ];
    }
}
