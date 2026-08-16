<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EntryDirection;
use App\Models\Account;
use App\Models\DailyAccountSummary;
use App\Models\EntryBalance;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Maintains `daily_account_summaries`.
 *
 * This table is a CACHE — fully derivable from entry_balances, rebuildable at
 * any time. It exists because day reports and dashboard charts would otherwise
 * scan the entries table on every request, which is the single biggest
 * performance decision in the system.
 *
 * Reports read ONLY from here. Never sum entries at request time.
 */
final readonly class DailySummaryService
{
    /**
     * Recompute one account-day from the underlying balance rows.
     *
     * Idempotent, which is what makes it safe to fire on every entry write and
     * to replay after a deletion.
     */
    public function refresh(int $accountId, string $date): ?DailyAccountSummary
    {
        /** @var Account|null $account */
        $account = Account::query()->whereKey($accountId)->first();

        if (! $account instanceof Account) {
            return null;
        }

        $rows = EntryBalance::query()
            ->forAccount($accountId)
            ->notReversed()
            ->whereDate('entry_date', $date)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            // No activity left that day — drop the row rather than leave a
            // stale zero behind after the last entry of a day is deleted.
            DailyAccountSummary::query()
                ->where('account_id', $accountId)
                ->whereDate('summary_date', $date)
                ->delete();

            return null;
        }

        $totalCredit = Money::ZERO;
        $totalDebit = Money::ZERO;
        $creditCount = 0;
        $debitCount = 0;

        foreach ($rows as $row) {
            $amount = Money::normalise((string) $row->amount);

            if ($row->direction === EntryDirection::In) {
                $totalCredit = Money::add($totalCredit, $amount);
                $creditCount++;
            } else {
                $totalDebit = Money::add($totalDebit, $amount);
                $debitCount++;
            }
        }

        return DailyAccountSummary::updateOrCreate(
            ['account_id' => $accountId, 'summary_date' => $date],
            [
                'user_id' => $account->user_id,
                'opening_balance' => Money::normalise((string) $rows->first()->opening_balance),
                'closing_balance' => Money::normalise((string) $rows->last()->closing_balance),
                'total_credit' => $totalCredit,
                'total_debit' => $totalDebit,
                'entry_count' => $rows->count(),
                'credit_count' => $creditCount,
                'debit_count' => $debitCount,
            ],
        );
    }

    /**
     * Rebuild every affected day from a date forward.
     *
     * Called after a mid-ledger deletion: the snapshots moved, so every rollup
     * from that date on is stale. Skipping this leaves reports quietly
     * disagreeing with the ledger — the kind of drift nobody notices until a
     * customer does.
     */
    public function rebuildFrom(int $accountId, string $fromDate): int
    {
        $dates = EntryBalance::query()
            ->forAccount($accountId)
            ->notReversed()
            ->where('entry_date', '>=', $fromDate)
            ->distinct()
            ->pluck('entry_date');

        foreach ($dates as $date) {
            // entry_date is cast to `date`, so this is a Carbon — take the date
            // part explicitly rather than stringifying a full datetime.
            $this->refresh($accountId, $date instanceof \DateTimeInterface
                ? $date->format('Y-m-d')
                : (string) $date);
        }

        return $dates->count();
    }

    /**
     * The carried-forward balance at the START of a date.
     *
     * Falls back to the account's opening balance when nothing precedes it, so
     * a report on an account's first-ever day still shows the right opening
     * rather than zero.
     */
    public function openingBalanceOn(Account $account, string $date): string
    {
        $previousClosing = DB::table('daily_account_summaries')
            ->where('account_id', $account->id)
            ->where('summary_date', '<', $date)
            ->orderByDesc('summary_date')
            ->value('closing_balance');

        return Money::normalise((string) ($previousClosing ?? $account->opening_balance));
    }
}
