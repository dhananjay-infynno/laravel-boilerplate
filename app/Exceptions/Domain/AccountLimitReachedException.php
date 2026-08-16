<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/**
 * The plan's account cap has been reached.
 *
 * 402 rather than 403: the clients turn any 402 into the paywall, and this is
 * an upgrade prompt, not a permissions failure.
 */
final class AccountLimitReachedException extends DomainException
{
    public function __construct(
        private readonly int $limit,
        private readonly int $current,
        private readonly string $planCode = '',
    ) {
        parent::__construct((string) __('errors.account_limit_reached', ['limit' => $limit]));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::AccountLimitReached;
    }

    /** The client renders "20 of 20 accounts used" from this. */
    public function meta(): array
    {
        return ['limit' => $this->limit, 'current' => $this->current, 'plan' => $this->planCode];
    }
}
