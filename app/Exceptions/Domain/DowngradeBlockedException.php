<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/**
 * Downgrading would leave the user over the new plan's account cap.
 *
 * A Pro user with 35 accounts cannot silently land on Basic (20) — the
 * overflow accounts would have to be hidden or deleted, and neither is
 * acceptable for financial records.
 */
final class DowngradeBlockedException extends DomainException
{
    /**
     * @param  array<int, array<string, mixed>>  $suggested  Empty, unused accounts
     */
    public function __construct(
        private readonly int $current,
        private readonly int $newLimit,
        private readonly array $suggested = [],
    ) {
        parent::__construct((string) __('errors.downgrade_blocked', [
            'count' => max(0, $current - $newLimit),
        ]));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::DowngradeBlocked;
    }

    /**
     * `suggested` lists the empty, never-used accounts, which turns a dead end
     * into a two-tap flow instead of leaving the user to work it out.
     */
    public function meta(): array
    {
        return [
            'current' => $this->current,
            'new_limit' => $this->newLimit,
            'must_remove' => max(0, $this->current - $this->newLimit),
            'suggested' => $this->suggested,
        ];
    }
}
