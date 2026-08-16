<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\Account\GenerateAccountNumberAction;
use App\Enums\AccountStatus;
use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Exceptions\Domain\AccountHasBalanceException;
use App\Exceptions\Domain\AccountLimitReachedException;
use App\Exceptions\Domain\CannotDeleteMainAccountException;
use App\Exceptions\Domain\EntryImmutableException;
use App\Models\Account;
use App\Models\Entry;
use App\Support\Money;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;

/**
 * Accounts — the reference slice. Copy this shape for every new domain.
 *
 * Takes DTOs or scalars in, returns MODELS out. It never builds a Resource,
 * never reads request(), never returns a JsonResponse — that keeps it usable
 * from a job, a console command or a test, not just from HTTP.
 */
final readonly class AccountService
{
    public function __construct(
        private EntitlementService $entitlements,
        private GenerateAccountNumberAction $generateNumber,
    ) {}

    public function paginate(int $userId, int $perPage = 25): CursorPaginator
    {
        return (new Account)->getQB()
            ->ownedBy($userId)
            ->orderBy('sort_order')
            // sort_order is not unique; without an id tiebreak, cursor
            // pagination silently drops rows that share one.
            ->orderBy('id')
            ->cursorPaginate($perPage)
            ->withQueryString();
    }

    /**
     * Aggregates for the list `meta`.
     *
     * Summed here with bcmath rather than SQL SUM() so the result is an exact
     * decimal string all the way to the client — SUM() on DECIMAL is safe, but
     * PHP would hand it back as a float and undo the point.
     *
     * @return array<string, mixed>
     */
    public function totals(int $userId): array
    {
        $balances = Account::query()
            ->ownedBy($userId)
            ->pluck('current_balance');

        return [
            'total_balance' => Money::sum($balances->map(fn ($b): string => (string) $b)),
            'account_count' => $balances->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(int $userId, array $input): Account
    {
        $entitlements = $this->entitlements->for($userId);

        // Counts LIVE rows regardless of status: otherwise a user could
        // deactivate accounts to sneak past the cap while keeping the data.
        $current = Account::query()->ownedBy($userId)->count();

        if ($current >= $entitlements->maxAccounts) {
            throw new AccountLimitReachedException(
                limit: $entitlements->maxAccounts,
                current: $current,
                planCode: $entitlements->planCode,
            );
        }

        return DB::transaction(function () use ($userId, $input, $current): Account {
            // The first account a user creates is always their main one.
            $isMain = (bool) ($input['is_main'] ?? false) || $current === 0;

            if ($isMain) {
                $this->demoteCurrentMain($userId);
            }

            $opening = Money::normalise((string) $input['opening_balance']);

            return Account::create([
                'user_id' => $userId,
                'account_number' => $this->generateNumber->handle(),
                'name' => $input['name'],
                'description' => $input['description'] ?? null,
                'currency_code' => $input['currency_code'] ?? 'INR',
                'opening_balance' => $opening,
                // Seeded from the opening balance — an account starts life
                // holding exactly what the user says it holds.
                'current_balance' => $opening,
                'is_main' => $isMain,
                'allow_overdraft' => (bool) ($input['allow_overdraft'] ?? false),
                'status' => AccountStatus::Active,
                'sort_order' => $current,
                'color' => $input['color'] ?? null,
                'icon' => $input['icon'] ?? null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Account $account, array $input): Account
    {
        // Belt and braces: the FormRequest omits opening_balance entirely, but
        // a future caller might not.
        if (array_key_exists('opening_balance', $input) && $this->hasEntries($account)) {
            throw new EntryImmutableException('opening_balance');
        }

        $account->update(array_intersect_key($input, array_flip([
            'name', 'description', 'status', 'allow_overdraft', 'color', 'icon', 'sort_order',
        ])));

        return $account->refresh();
    }

    public function delete(Account $account): void
    {
        // Money must not be stranded: a deleted account holding a balance
        // breaks the ledger invariant.
        if (! Money::isZero((string) $account->current_balance)) {
            throw new AccountHasBalanceException((string) $account->current_balance);
        }

        if ($account->is_main) {
            throw new CannotDeleteMainAccountException;
        }

        // A pending transfer names this account as its destination. Deleting it
        // would leave the sender's money with nowhere to land.
        $hasPending = Entry::query()
            ->where('type', EntryType::ExternalTransfer)
            ->where('status', EntryStatus::Pending)
            ->where(function ($q) use ($account): void {
                $q->where('from_account_id', $account->id)
                    ->orWhere('counterparty_account_id', $account->id);
            })
            ->exists();

        if ($hasPending) {
            throw new AccountHasBalanceException((string) $account->current_balance);
        }

        // HasUserActions stamps deleted_by on the way out.
        DB::transaction(static fn () => $account->delete());
    }

    public function setMain(Account $account): Account
    {
        return DB::transaction(function () use ($account): Account {
            $this->demoteCurrentMain((int) $account->user_id);

            $account->update(['is_main' => true]);

            return $account->refresh();
        });
    }

    /**
     * @param  array<int, array{uuid: string, sort_order: int}>  $order
     */
    public function reorder(int $userId, array $order): void
    {
        DB::transaction(function () use ($userId, $order): void {
            foreach ($order as $item) {
                // Scoped to the caller: a uuid they do not own simply matches
                // nothing rather than reordering someone else's list.
                Account::query()
                    ->ownedBy($userId)
                    ->where('uuid', $item['uuid'])
                    ->update(['sort_order' => $item['sort_order']]);
            }
        });
    }

    /**
     * External-transfer lookup.
     *
     * Returns only what a sender needs to confirm they have the right person.
     * Never reveals whether a number exists when it cannot receive — see
     * ExternalTransfersDisabledException.
     *
     * @return array{account_number: string, holder_name_masked: string, accepts_transfers: bool}|null
     */
    public function lookup(string $accountNumber): ?array
    {
        /** @var Account|null $account */
        $account = Account::query()
            ->with('user.settings')
            ->where('account_number', strtoupper(trim($accountNumber)))
            ->where('status', AccountStatus::Active)
            ->first();

        if (! $account instanceof Account) {
            return null;
        }

        if (! (bool) ($account->user?->settings?->allow_external_transfers ?? true)) {
            return null;
        }

        return [
            'account_number' => (string) $account->account_number,
            'holder_name_masked' => $this->maskName((string) ($account->user?->name ?? '')),
            'accepts_transfers' => true,
        ];
    }

    private function demoteCurrentMain(int $userId): void
    {
        Account::query()
            ->ownedBy($userId)
            ->where('is_main', true)
            ->update(['is_main' => false]);
    }

    private function hasEntries(Account $account): bool
    {
        return Entry::query()
            ->withTrashed()
            ->where(function ($q) use ($account): void {
                $q->where('from_account_id', $account->id)
                    ->orWhere('to_account_id', $account->id);
            })
            ->exists();
    }

    /**
     * "Dhananjay Thakkar" -> "Dha***** T."
     *
     * Enough for the sender to recognise the person they meant; not enough to
     * harvest a name directory by walking account numbers.
     */
    private function maskName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? '';

        $masked = mb_strlen($first) <= 3
            ? $first
            : mb_substr($first, 0, 3).str_repeat('*', max(1, mb_strlen($first) - 3));

        $lastInitial = count($parts) > 1 ? ' '.mb_substr((string) end($parts), 0, 1).'.' : '';

        return $masked.$lastInitial;
    }
}
