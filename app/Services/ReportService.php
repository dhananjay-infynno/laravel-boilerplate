<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\DailyAccountSummary;
use App\Models\EntryBalance;
use App\Support\Money;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Collection;

/**
 * Reports — `docs/02-API-SPEC.md` §7.
 *
 * Every aggregate reads `daily_account_summaries`, NEVER `entries`. Summing the
 * ledger at request time works fine on a demo account and falls over on a real
 * one; the rollup table exists precisely so this layer stays O(days).
 *
 * The account statement is the one exception — it is inherently row-level and
 * reads entry_balances with cursor pagination.
 */
final readonly class ReportService
{
    /** Longer than this goes through the export flow, not a synchronous report. */
    private const MAX_RANGE_DAYS = 366;

    public function __construct(
        private DailySummaryService $summaries,
    ) {}

    /**
     * Day report: every account's opening, movement and closing for one date.
     *
     * @return array<string, mixed>
     */
    public function day(int $userId, string $date, ?string $accountUuid = null): array
    {
        $accounts = $this->accountsFor($userId, $accountUuid);

        $rows = DailyAccountSummary::query()
            ->where('user_id', $userId)
            ->whereDate('summary_date', $date)
            ->get()
            ->keyBy('account_id');

        $accountRows = [];
        $totalCredit = Money::ZERO;
        $totalDebit = Money::ZERO;
        $totalClosing = Money::ZERO;

        foreach ($accounts as $account) {
            $summary = $rows->get($account->id);

            /*
             * An account with no activity that day still appears, carrying its
             * balance forward. A day report that hides quiet accounts is not a
             * day report — the user is checking totals, not just movement.
             */
            $opening = $summary !== null
                ? Money::normalise((string) $summary->opening_balance)
                : $this->summaries->openingBalanceOn($account, $date);

            $closing = $summary !== null
                ? Money::normalise((string) $summary->closing_balance)
                : $opening;

            $credit = Money::normalise((string) ($summary->total_credit ?? Money::ZERO));
            $debit = Money::normalise((string) ($summary->total_debit ?? Money::ZERO));

            $totalCredit = Money::add($totalCredit, $credit);
            $totalDebit = Money::add($totalDebit, $debit);
            $totalClosing = Money::add($totalClosing, $closing);

            $accountRows[] = [
                'account' => $this->accountRef($account),
                'opening_balance' => $opening,
                'total_credit' => $credit,
                'total_debit' => $debit,
                'closing_balance' => $closing,
                'entry_count' => (int) ($summary->entry_count ?? 0),
            ];
        }

        return [
            'date' => $date,
            'accounts' => $accountRows,
            'totals' => [
                'total_credit' => $totalCredit,
                'total_debit' => $totalDebit,
                'net' => Money::sub($totalCredit, $totalDebit),
                'closing_balance' => $totalClosing,
            ],
        ];
    }

    /** Running-balance rows for one account. Cursor paginated. */
    public function statement(Account $account, string $from, string $to, int $perPage = 50): CursorPaginator
    {
        $this->guardRange($from, $to);

        return EntryBalance::query()
            ->forAccount((int) $account->id)
            ->notReversed()
            ->between($from, $to)
            ->with('entry:id,uuid,sr_no,type,remarks,reference_no,entry_date')
            ->orderBy('entry_date')
            ->orderBy('id')
            ->cursorPaginate($perPage)
            ->withQueryString();
    }

    /**
     * Account report header.
     *
     * @return array<string, mixed>
     */
    public function accountSummary(Account $account, string $from, string $to): array
    {
        $this->guardRange($from, $to);

        $rows = DailyAccountSummary::query()
            ->where('account_id', $account->id)
            ->whereBetween('summary_date', [$from, $to])
            ->orderBy('summary_date')
            ->get();

        $opening = $rows->isNotEmpty()
            ? Money::normalise((string) $rows->first()->opening_balance)
            : $this->summaries->openingBalanceOn($account, $from);

        return [
            'account' => $this->accountRef($account),
            'from' => $from,
            'to' => $to,
            'opening_balance' => $opening,
            'closing_balance' => $rows->isNotEmpty()
                ? Money::normalise((string) $rows->last()->closing_balance)
                : $opening,
            'total_credit' => $this->sum($rows, 'total_credit'),
            'total_debit' => $this->sum($rows, 'total_debit'),
            'entry_count' => (int) $rows->sum('entry_count'),
        ];
    }

    /**
     * Totals across every account for a period.
     *
     * @return array<string, mixed>
     */
    public function summary(int $userId, string $from, string $to): array
    {
        $this->guardRange($from, $to);

        $rows = DailyAccountSummary::query()
            ->where('user_id', $userId)
            ->whereBetween('summary_date', [$from, $to])
            ->get();

        $totalCredit = $this->sum($rows, 'total_credit');
        $totalDebit = $this->sum($rows, 'total_debit');

        $perAccount = $rows->groupBy('account_id')->map(function (Collection $group): array {
            $ordered = $group->sortBy('summary_date');

            return [
                'opening_balance' => Money::normalise((string) $ordered->first()->opening_balance),
                'closing_balance' => Money::normalise((string) $ordered->last()->closing_balance),
                'total_credit' => $this->sum($ordered, 'total_credit'),
                'total_debit' => $this->sum($ordered, 'total_debit'),
                'entry_count' => (int) $ordered->sum('entry_count'),
            ];
        });

        $accounts = Account::query()
            ->ownedBy($userId)
            ->get()
            ->map(fn (Account $a): array => array_merge(
                ['account' => $this->accountRef($a)],
                $perAccount->get($a->id) ?? [
                    // No movement in the window — show the balance it holds
                    // rather than omitting the account.
                    'opening_balance' => Money::normalise((string) $a->current_balance),
                    'closing_balance' => Money::normalise((string) $a->current_balance),
                    'total_credit' => Money::ZERO,
                    'total_debit' => Money::ZERO,
                    'entry_count' => 0,
                ],
            ))
            ->values()
            ->all();

        return [
            'from' => $from,
            'to' => $to,
            'totals' => [
                'total_credit' => $totalCredit,
                'total_debit' => $totalDebit,
                'net' => Money::sub($totalCredit, $totalDebit),
                'entry_count' => (int) $rows->sum('entry_count'),
            ],
            'accounts' => $accounts,
        ];
    }

    /**
     * Time series for the dashboard chart.
     *
     * @return array<int, array<string, mixed>>
     */
    public function chart(int $userId, string $from, string $to, ?string $accountUuid = null): array
    {
        $this->guardRange($from, $to);

        $query = DailyAccountSummary::query()
            ->where('user_id', $userId)
            ->whereBetween('summary_date', [$from, $to]);

        if ($accountUuid !== null) {
            $accountId = Account::query()->ownedBy($userId)->where('uuid', $accountUuid)->value('id');
            $query->where('account_id', $accountId ?? 0);
        }

        return $query->get()
            // toDateString(), not (string): summary_date casts to a Carbon, and
            // stringifying yields a full datetime that puts every row in its
            // own group.
            ->groupBy(fn (DailyAccountSummary $s): string => $s->summary_date->toDateString())
            ->map(function (Collection $day, string $date): array {
                $credit = $this->sum($day, 'total_credit');
                $debit = $this->sum($day, 'total_debit');

                return [
                    'date' => $date,
                    'total_credit' => $credit,
                    'total_debit' => $debit,
                    'net' => Money::sub($credit, $debit),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Account>
     */
    private function accountsFor(int $userId, ?string $accountUuid): Collection
    {
        return Account::query()
            ->ownedBy($userId)
            ->where('status', AccountStatus::Active)
            ->when($accountUuid !== null, fn ($q) => $q->where('uuid', $accountUuid))
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    private function accountRef(Account $account): array
    {
        return [
            'uuid' => (string) $account->uuid,
            'name' => (string) $account->name,
            'account_number' => (string) $account->account_number,
        ];
    }

    /**
     * @param  Collection<int, DailyAccountSummary>  $rows
     */
    private function sum(Collection $rows, string $column): string
    {
        return Money::sum($rows->map(fn (DailyAccountSummary $r): string => (string) $r->{$column}));
    }

    private function guardRange(string $from, string $to): void
    {
        $days = (strtotime($to) - strtotime($from)) / 86400;

        if ($days > self::MAX_RANGE_DAYS) {
            // A synchronous multi-year report is a timeout waiting to happen.
            abort(422, (string) __('report.range_too_wide', ['days' => self::MAX_RANGE_DAYS]));
        }
    }
}
