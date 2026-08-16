<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/**
 * Amount, date, type and accounts are immutable on a posted entry — changing
 * any of them would invalidate every downstream balance snapshot. Delete and
 * re-create instead.
 *
 * Also thrown when deleting one side of an ACCEPTED external transfer, which
 * would corrupt the counterparty's ledger.
 */
final class EntryImmutableException extends DomainException
{
    public function __construct(private readonly string $field = 'entry')
    {
        parent::__construct((string) __('errors.entry_immutable', ['field' => $field]));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::EntryImmutable;
    }

    public function meta(): array
    {
        return ['field' => $this->field];
    }
}
