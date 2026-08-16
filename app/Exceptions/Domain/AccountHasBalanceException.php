<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/**
 * Deleting an account that still holds money would strand it — the ledger would
 * no longer reconcile. The user must move the balance out first.
 */
final class AccountHasBalanceException extends DomainException
{
    public function __construct(private readonly string $balance = '0.0000')
    {
        parent::__construct((string) __('errors.account_has_balance'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::AccountHasBalance;
    }

    public function meta(): array
    {
        return ['current_balance' => $this->balance];
    }
}
