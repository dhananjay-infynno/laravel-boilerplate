<?php

declare(strict_types=1);

namespace App\Services;

use App\DataObjects\CreateEntryData;
use App\Enums\AccountStatus;
use App\Enums\EntryDirection;
use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Exceptions\Domain\AccountInactiveException;
use App\Exceptions\Domain\EntryImmutableException;
use App\Exceptions\Domain\InsufficientBalanceException;
use App\Jobs\Entry\RecalculateAccountBalancesJob;
use App\Jobs\Entry\RefreshDailySummaryJob;
use App\Models\Account;
use App\Models\Entry;
use App\Models\EntryBalance;
use App\Support\Money;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * THE MOST IMPORTANT FILE IN THIS CODEBASE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The invariant it exists to protect:
 *
 *   The sum of an account's entries must always equal its current balance, and
 *   every balance change must have exactly one entry that caused it.
 *
 * Rules that are NOT negotiable here:
 *
 *   1. Every balance change inside DB::transaction().
 *   2. Every affected account locked with lockForUpdate(), ORDERED BY ID ASC.
 *      Consistent ordering is the ONLY thing preventing a deadlock when A→B
 *      and B→A run at the same instant.
 *   3. All arithmetic through App\Support\Money (bcmath, scale 4). Never +, -,
 *      ==, < or > on money.
 *   4. Nothing slow inside the transaction — no mail, push, HTTP or file IO.
 *      Every lock held is a lock another request is waiting on.
 *   5. Queued jobs dispatched with ->afterCommit(). Without it a worker can
 *      pick the job up before the rows are visible, and it only fails under
 *      load, which means only in production.
 *
 * If you are an AI agent: tests/Feature/Concurrency and tests/Feature/Ledger
 * are the specification for this file. Do NOT modify them to make code pass.
 */
final readonly class EntryService
{
    public function __construct(
        private UserSequenceService $sequences,
    ) {}

    public function paginate(int $userId, int $perPage = 25): CursorPaginator
    {
        return (new Entry)->getQB()
            ->ownedBy($userId)
            ->with(['fromAccount:id,uuid,name,account_number', 'toAccount:id,uuid,name,account_number'])
            // spatie's defaultSort (entry_date) is NOT unique. Without an id
            // tiebreak, cursor pagination silently skips rows that share a date.
            ->orderBy('id', 'desc')
            ->cursorPaginate($perPage)
            ->withQueryString();
    }

    /**
     * Create a CREDIT, DEBIT or ACCOUNT_TO_ACCOUNT entry.
     *
     * External transfers do NOT come through here — they move no money until
     * accepted, and live in ExternalTransferService.
     *
     * Returns the entry plus the affected accounts' new balances so the client
     * updates its cache without a second round trip.
     *
     * @return array{entry: Entry, balances: array<int, array{account_uuid: string, current_balance: string}>}
     */
    public function create(CreateEntryData $data): array
    {
        if ($data->type === EntryType::ExternalTransfer) {
            throw new EntryImmutableException('type');
        }

        $amount = Money::normalise($data->amount);

        $result = DB::transaction(function () use ($data, $amount): array {
            // (2) Lock everything this entry touches, in ascending id order.
            $accounts = Account::query()
                ->whereIn('id', $data->accountIds())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $this->guard($accounts);

            $entry = Entry::create([
                'user_id' => $data->userId,
                // Atomic per-user counter. Never MAX(sr_no) + 1.
                'sr_no' => $this->sequences->nextEntryNo($data->userId),
                'entry_date' => $data->entryDate,
                'entry_time' => $data->entryTime,
                'type' => $data->type,
                'direction' => $data->direction(),
                'from_account_id' => $data->fromAccountId,
                'to_account_id' => $data->toAccountId,
                'amount' => $amount,
                'currency_code' => $accounts->first()?->currency_code ?? 'INR',
                'status' => EntryStatus::Completed,
                'remarks' => $data->remarks,
                'reference_no' => $data->referenceNo,
                'category_id' => $data->categoryId,
                'party_id' => $data->partyId,
                'idempotency_key' => $data->idempotencyKey,
            ]);

            $balances = [];

            foreach ($this->legs($data, $accounts) as [$account, $direction]) {
                $balances[] = $this->applyLeg($account, $entry, $direction, $amount, $data);
            }

            return ['entry' => $entry, 'balances' => $balances];
        });

        // (5) Outside the transaction, afterCommit: the summary is derived from
        // entry_balances rows that do not exist until the commit lands.
        RefreshDailySummaryJob::dispatch($data->accountIds(), $data->entryDate)->afterCommit();

        return $result;
    }

    /**
     * Soft delete, reverse, then replay.
     *
     * Users expect a deleted entry to vanish, which breaks the running balance
     * of every later entry on the affected accounts. So:
     *
     *   - `current_balance` is reversed SYNCHRONOUSLY — it must be correct the
     *     moment this returns, because the next screen reads it
     *   - the snapshot chain is replayed on a queue, scoped to one account from
     *     one date, which for a personal ledger is hundreds of rows
     *
     * The serial number is NOT returned to the pool. sr_no is an audit trail;
     * reusing one would make two different entries share an identity.
     */
    public function delete(Entry $entry): void
    {
        // Deleting one side of an accepted transfer would corrupt the
        // counterparty's ledger. They must post a reversing entry instead.
        if ($entry->type === EntryType::ExternalTransfer && $entry->status === EntryStatus::Accepted) {
            throw new EntryImmutableException('external_transfer');
        }

        $accountIds = array_values(array_filter([$entry->from_account_id, $entry->to_account_id]));
        sort($accountIds);

        $entryDate = $entry->entry_date instanceof \DateTimeInterface
            ? $entry->entry_date->format('Y-m-d')
            : (string) $entry->entry_date;

        DB::transaction(function () use ($entry, $accountIds): void {
            $accounts = Account::query()
                ->whereIn('id', $accountIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $amount = Money::normalise((string) $entry->amount);

            foreach ($entry->balances()->get() as $balance) {
                $account = $accounts->get($balance->account_id);

                if (! $account instanceof Account) {
                    continue;
                }

                // Reverse the leg: an IN leg subtracts back, an OUT leg adds.
                $current = Money::normalise((string) $account->current_balance);

                $account->update([
                    'current_balance' => $balance->direction === EntryDirection::In
                        ? Money::sub($current, $amount)
                        : Money::add($current, $amount),
                    'is_recalculating' => true,
                ]);

                // Flag, never delete — entry_balances is the audit trail.
                $balance->update(['is_reversed' => true]);
            }

            $entry->delete();
        });

        foreach ($accountIds as $accountId) {
            RecalculateAccountBalancesJob::dispatch($accountId, $entryDate)->afterCommit();
        }
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function guard(Collection $accounts): void
    {
        foreach ($accounts as $account) {
            if ($account->status !== AccountStatus::Active) {
                throw new AccountInactiveException((string) $account->uuid);
            }
        }
    }

    /**
     * Which accounts move, and in which direction.
     *
     * Note an ACCOUNT_TO_ACCOUNT transfer is ONE entry with TWO legs — not two
     * entries. That is what keeps a transfer atomic and self-describing.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array<int, array{0: Account, 1: EntryDirection}>
     */
    private function legs(CreateEntryData $data, Collection $accounts): array
    {
        return match ($data->type) {
            EntryType::CreditEntry => [
                [$accounts->get($data->toAccountId), EntryDirection::In],
            ],
            EntryType::DebitEntry => [
                [$accounts->get($data->fromAccountId), EntryDirection::Out],
            ],
            EntryType::AccountToAccount => [
                [$accounts->get($data->fromAccountId), EntryDirection::Out],
                [$accounts->get($data->toAccountId), EntryDirection::In],
            ],
            EntryType::ExternalTransfer => [],
        };
    }

    /**
     * Apply one leg: move the running balance and append the immutable snapshot.
     *
     * @return array{account_uuid: string, current_balance: string}
     */
    private function applyLeg(
        Account $account,
        Entry $entry,
        EntryDirection $direction,
        string $amount,
        CreateEntryData $data,
    ): array {
        $opening = Money::normalise((string) $account->current_balance);

        $closing = $direction === EntryDirection::In
            ? Money::add($opening, $amount)
            : Money::sub($opening, $amount);

        if (Money::isNegative($closing) && ! $account->allow_overdraft) {
            throw new InsufficientBalanceException((string) $account->uuid, $amount, $opening);
        }

        $account->update(['current_balance' => $closing]);

        EntryBalance::create([
            'sr_no' => $this->sequences->nextBalanceNo($data->userId),
            'user_id' => $data->userId,
            'account_id' => $account->id,
            'entry_id' => $entry->id,
            'entry_date' => $data->entryDate,
            'direction' => $direction,
            'amount' => $amount,
            'opening_balance' => $opening,
            'closing_balance' => $closing,
        ]);

        return [
            'account_uuid' => (string) $account->uuid,
            'current_balance' => $closing,
        ];
    }
}
