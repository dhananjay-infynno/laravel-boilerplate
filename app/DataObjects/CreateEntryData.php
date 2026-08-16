<?php

declare(strict_types=1);

namespace App\DataObjects;

use App\Enums\EntryDirection;
use App\Enums\EntryType;

/**
 * Input to EntryService::create().
 *
 * Account ids are INTERNAL ids, already resolved and ownership-checked by the
 * FormRequest. The service never sees a raw uuid from the client, which is what
 * stops a crafted payload posting into somebody else's ledger.
 */
final readonly class CreateEntryData
{
    public function __construct(
        public int $userId,
        public EntryType $type,
        public string $entryDate,
        public string $amount,
        public ?int $fromAccountId = null,
        public ?int $toAccountId = null,
        public ?string $remarks = null,
        public ?string $referenceNo = null,
        public ?int $categoryId = null,
        public ?int $partyId = null,
        public ?string $entryTime = null,
        public ?string $idempotencyKey = null,
    ) {}

    public function direction(): EntryDirection
    {
        return $this->type->defaultDirection();
    }

    /**
     * Affected account ids, ALWAYS sorted ascending.
     *
     * The ordering is the deadlock defence: two concurrent transfers, A→B and
     * B→A, that lock in argument order will deadlock. Sorted, both take the
     * same lock first and one simply waits.
     *
     * @return array<int, int>
     */
    public function accountIds(): array
    {
        $ids = array_values(array_unique(array_filter([$this->fromAccountId, $this->toAccountId])));
        sort($ids);

        return $ids;
    }
}
