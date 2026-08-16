<?php

declare(strict_types=1);

namespace App\Jobs\Entry;

use App\Services\DailySummaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Refreshes one account-day of `daily_account_summaries`.
 *
 * Dispatched with ->afterCommit() from EntryService: the summary is derived
 * from entry_balances rows that do not exist until the transaction commits.
 * Dispatching inside would let a worker read nothing and write a zero — a
 * failure that only appears under load.
 *
 * Deliberately NOT ShouldBeUnique: two entries on the same account-day must
 * both trigger a refresh, and the operation is idempotent anyway.
 */
final class RefreshDailySummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @param  array<int, int>  $accountIds
     */
    public function __construct(
        private readonly array $accountIds,
        private readonly string $date,
    ) {}

    public function handle(DailySummaryService $summaries): void
    {
        foreach ($this->accountIds as $accountId) {
            $summaries->refresh($accountId, $this->date);
        }
    }
}
