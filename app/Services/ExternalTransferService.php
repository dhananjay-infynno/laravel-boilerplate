<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\EntryDirection;
use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Exceptions\Domain\AccountInactiveException;
use App\Exceptions\Domain\ExternalTransfersDisabledException;
use App\Exceptions\Domain\InsufficientBalanceException;
use App\Exceptions\Domain\TransferNotPendingException;
use App\Models\Account;
use App\Models\Entry;
use App\Models\EntryBalance;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * User-to-user transfers — `docs/00-MASTER-PLAN.md` §4.7.
 *
 *      PENDING ──accept──▶ ACCEPTED     (the ONLY edge that moves money)
 *          ├────reject───▶ REJECTED
 *          ├────cancel───▶ CANCELLED    (sender only, while pending)
 *          └────expire───▶ EXPIRED      (no response in 7 days)
 *
 * A PENDING transfer does NOT touch the sender's balance. No escrow, no
 * phantom hold — the sender keeps full use of their money while the request
 * sits unanswered, which is what a user expects.
 *
 * The trade-off is that accept can legitimately fail with INSUFFICIENT_BALANCE
 * if the sender spent it meanwhile. Both parties are notified when that
 * happens: the sender needs to know their transfer failed just as much as the
 * receiver does.
 */
final readonly class ExternalTransferService
{
    private const EXPIRY_DAYS = 7;

    public function __construct(
        private UserSequenceService $sequences,
    ) {}

    /**
     * @param  'sent'|'received'|null  $direction
     */
    public function paginate(int $userId, ?string $direction = null, int $perPage = 25): CursorPaginator
    {
        return Entry::query()
            ->where('type', EntryType::ExternalTransfer)
            ->with(['fromAccount:id,uuid,name', 'toAccount:id,uuid,name'])
            ->when(
                $direction === 'received',
                fn ($q) => $q->where('counterparty_user_id', $userId)->where('user_id', '!=', $userId),
                fn ($q) => $direction === 'sent'
                    ? $q->where('user_id', $userId)
                    : $q->where(fn ($w) => $w->where('user_id', $userId)->orWhere('counterparty_user_id', $userId)),
            )
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->cursorPaginate($perPage)
            ->withQueryString();
    }

    /** Create a PENDING request. Moves no money. */
    public function create(
        User $sender,
        string $fromAccountUuid,
        string $toAccountNumber,
        string $amount,
        string $entryDate,
        ?string $remarks = null,
        ?string $idempotencyKey = null,
    ): Entry {
        $amount = Money::normalise($amount);

        /** @var Account|null $from */
        $from = Account::query()->ownedBy((int) $sender->id)->where('uuid', $fromAccountUuid)->first();

        if (! $from instanceof Account || $from->status !== AccountStatus::Active) {
            throw new AccountInactiveException($fromAccountUuid);
        }

        /** @var Account|null $target */
        $target = Account::query()
            ->with('user.settings')
            ->where('account_number', strtoupper(trim($toAccountNumber)))
            ->where('status', AccountStatus::Active)
            ->first();

        /*
         * ONE failure for every reason: unknown number, inactive, deleted,
         * transfers switched off, or the sender's own account.
         *
         * Any observable difference — status, message, even timing — lets
         * someone walk the account-number space and map real users.
         */
        if (! $target instanceof Account
            || $target->user_id === $sender->id
            || ! $this->acceptsTransfers($target)) {
            throw new ExternalTransfersDisabledException;
        }

        return DB::transaction(fn (): Entry => Entry::create([
            'user_id' => $sender->id,
            'sr_no' => $this->sequences->nextEntryNo((int) $sender->id),
            'entry_date' => $entryDate,
            'type' => EntryType::ExternalTransfer,
            'direction' => EntryDirection::Out,
            'from_account_id' => $from->id,
            'amount' => $amount,
            'currency_code' => $from->currency_code,
            'status' => EntryStatus::Pending,
            'remarks' => $remarks,
            'counterparty_user_id' => $target->user_id,
            'counterparty_account_id' => $target->id,
            'expires_at' => Carbon::now()->addDays(self::EXPIRY_DAYS),
            'idempotency_key' => $idempotencyKey,
        ]));
    }

    /**
     * The only edge that moves money.
     *
     * One transaction, both accounts locked in ascending id order, two entries
     * (one per book) and two balance snapshots.
     */
    public function accept(Entry $transfer, User $receiver, string $toAccountUuid): Entry
    {
        $this->assertPending($transfer);
        $this->assertReceiver($transfer, $receiver);

        /** @var Account|null $to */
        $to = Account::query()->ownedBy((int) $receiver->id)->where('uuid', $toAccountUuid)->first();

        if (! $to instanceof Account || $to->status !== AccountStatus::Active) {
            throw new AccountInactiveException($toAccountUuid);
        }

        $amount = Money::normalise((string) $transfer->amount);

        return DB::transaction(function () use ($transfer, $receiver, $to, $amount): Entry {
            $ids = [$transfer->from_account_id, $to->id];
            sort($ids);

            $accounts = Account::query()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

            $from = $accounts->get($transfer->from_account_id);
            $target = $accounts->get($to->id);

            /*
             * Re-read the transfer UNDER LOCK and re-check its status.
             *
             * Two taps on accept, or two devices, must not both succeed —
             * checking before acquiring the lock would let both through and
             * move the money twice.
             */
            /** @var Entry|null $fresh */
            $fresh = Entry::query()->whereKey($transfer->id)->lockForUpdate()->first();

            if (! $fresh instanceof Entry || $fresh->status !== EntryStatus::Pending) {
                throw new TransferNotPendingException((string) ($fresh?->status->value ?? 'unknown'));
            }

            // Debit the sender.
            $senderOpening = Money::normalise((string) $from->current_balance);
            $senderClosing = Money::sub($senderOpening, $amount);

            if (Money::isNegative($senderClosing) && ! $from->allow_overdraft) {
                // The sender spent it while this sat pending. Both parties are
                // notified — the sender needs to know as much as the receiver.
                throw new InsufficientBalanceException((string) $from->uuid, $amount, $senderOpening);
            }

            $from->update(['current_balance' => $senderClosing]);

            $fresh->update([
                'status' => EntryStatus::Accepted,
                'responded_at' => Carbon::now(),
            ]);

            EntryBalance::create([
                'sr_no' => $this->sequences->nextBalanceNo((int) $fresh->user_id),
                'user_id' => $fresh->user_id,
                'account_id' => $from->id,
                'entry_id' => $fresh->id,
                'entry_date' => $fresh->entry_date,
                'direction' => EntryDirection::Out,
                'amount' => $amount,
                'opening_balance' => $senderOpening,
                'closing_balance' => $senderClosing,
            ]);

            // The mirror entry. The receiver's ledger needs its OWN row with
            // its own sr_no — a shared entry would break both books' numbering.
            $mirror = Entry::create([
                'user_id' => $receiver->id,
                'sr_no' => $this->sequences->nextEntryNo((int) $receiver->id),
                'entry_date' => $fresh->entry_date,
                'type' => EntryType::ExternalTransfer,
                'direction' => EntryDirection::In,
                'to_account_id' => $target->id,
                'amount' => $amount,
                'currency_code' => $target->currency_code,
                'status' => EntryStatus::Accepted,
                'remarks' => $fresh->remarks,
                'counterparty_user_id' => $fresh->user_id,
                'counterparty_account_id' => $fresh->from_account_id,
                'linked_entry_id' => $fresh->id,
                'responded_at' => Carbon::now(),
            ]);

            $fresh->update(['linked_entry_id' => $mirror->id]);

            $receiverOpening = Money::normalise((string) $target->current_balance);
            $receiverClosing = Money::add($receiverOpening, $amount);

            $target->update(['current_balance' => $receiverClosing]);

            EntryBalance::create([
                'sr_no' => $this->sequences->nextBalanceNo((int) $receiver->id),
                'user_id' => $receiver->id,
                'account_id' => $target->id,
                'entry_id' => $mirror->id,
                'entry_date' => $mirror->entry_date,
                'direction' => EntryDirection::In,
                'amount' => $amount,
                'opening_balance' => $receiverOpening,
                'closing_balance' => $receiverClosing,
            ]);

            return $fresh->refresh();
        });
    }

    public function reject(Entry $transfer, User $receiver, ?string $reason = null): Entry
    {
        $this->assertPending($transfer);
        $this->assertReceiver($transfer, $receiver);

        $transfer->update([
            'status' => EntryStatus::Rejected,
            'responded_at' => Carbon::now(),
            'remarks' => $reason !== null
                ? trim(($transfer->remarks ?? '').' — '.$reason)
                : $transfer->remarks,
        ]);

        return $transfer;
    }

    public function cancel(Entry $transfer, User $sender): Entry
    {
        $this->assertPending($transfer);

        if ($transfer->user_id !== $sender->id) {
            throw new TransferNotPendingException((string) $transfer->status->value);
        }

        $transfer->update([
            'status' => EntryStatus::Cancelled,
            'responded_at' => Carbon::now(),
        ]);

        return $transfer;
    }

    /**
     * Swept every 15 minutes.
     *
     * Without it a request sits PENDING forever, the sender never learns their
     * money was not committed, and ledger invariant 6 fails.
     */
    public function expireStale(): int
    {
        return Entry::query()
            ->where('type', EntryType::ExternalTransfer)
            ->where('status', EntryStatus::Pending)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now())
            ->update([
                'status' => EntryStatus::Expired,
                'responded_at' => Carbon::now(),
            ]);
    }

    public function pendingCountFor(int $userId): int
    {
        return Entry::query()
            ->where('type', EntryType::ExternalTransfer)
            ->where('status', EntryStatus::Pending)
            ->where('counterparty_user_id', $userId)
            ->count();
    }

    private function acceptsTransfers(Account $account): bool
    {
        return (bool) ($account->user?->settings?->allow_external_transfers ?? true);
    }

    private function assertPending(Entry $transfer): void
    {
        if ($transfer->type !== EntryType::ExternalTransfer || $transfer->status !== EntryStatus::Pending) {
            throw new TransferNotPendingException((string) $transfer->status->value);
        }
    }

    private function assertReceiver(Entry $transfer, User $receiver): void
    {
        if ($transfer->counterparty_user_id !== $receiver->id) {
            // 404-shaped rather than 403: confirming the transfer exists would
            // leak that money was sent to a given account.
            throw new TransferNotPendingException((string) $transfer->status->value);
        }
    }
}
