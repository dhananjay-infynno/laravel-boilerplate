<?php

declare(strict_types=1);

namespace App\Jobs\Entry;

use App\Enums\EntryDirection;
use App\Models\Account;
use App\Models\EntryBalance;
use App\Services\DailySummaryService;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Replays `entry_balances` for one account from a date forward.
 *
 * Deleting a mid-ledger entry leaves every later snapshot with a stale
 * opening/closing pair. `accounts.current_balance` was already corrected
 * synchronously by EntryService::delete() — this repairs the audit trail so
 * statements read correctly, then rebuilds the affected daily rollups.
 *
 * Scoped to one account from one date, so for a personal ledger this is
 * hundreds of rows, not millions.
 */
final class RecalculateAccountBalancesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        private readonly int $accountId,
        private readonly string $fromDate,
    ) {}

    /** One replay per account at a time — two would race each other. */
    public function uniqueId(): string
    {
        return "recalc-account-{$this->accountId}";
    }

    public function handle(DailySummaryService $summaries): void
    {
        DB::transaction(function (): void {
            /** @var Account|null $account */
            $account = Account::query()->whereKey($this->accountId)->lockForUpdate()->first();

            if (! $account instanceof Account) {
                return;
            }

            // Carry forward from the last untouched snapshot before the cutoff.
            $running = EntryBalance::query()
                ->forAccount($this->accountId)
                ->notReversed()
                ->where('entry_date', '<', $this->fromDate)
                ->orderByDesc('entry_date')
                ->orderByDesc('id')
                ->value('closing_balance');

            $running = Money::normalise((string) ($running ?? $account->opening_balance));

            $rows = EntryBalance::query()
                ->forAccount($this->accountId)
                ->notReversed()
                ->where('entry_date', '>=', $this->fromDate)
                ->orderBy('entry_date')
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $amount = Money::normalise((string) $row->amount);

                $opening = $running;
                $closing = $row->direction === EntryDirection::In
                    ? Money::add($opening, $amount)
                    : Money::sub($opening, $amount);

                // The CHECK constraint asserts closing = opening ± amount, so
                // both columns MUST be written together — a partial update is
                // rejected by MySQL.
                $row->update([
                    'opening_balance' => $opening,
                    'closing_balance' => $closing,
                ]);

                $running = $closing;
            }

            $account->update([
                'current_balance' => $running,
                'is_recalculating' => false,
            ]);
        });

        // The snapshots just moved, so every rollup from this date forward is
        // stale.
        $summaries->rebuildFrom($this->accountId, $this->fromDate);
    }

    public function failed(Throwable $e): void
    {
        // The account is left flagged `is_recalculating`. Reads still work —
        // current_balance was corrected synchronously — but the statement view
        // shows a warning until this is resolved. Alert loudly.
        Log::error('Balance recalculation failed — account left flagged.', [
            'account_id' => $this->accountId,
            'from_date' => $this->fromDate,
            'error' => $e->getMessage(),
        ]);
    }
}
