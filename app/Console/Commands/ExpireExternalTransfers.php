<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ExternalTransferService;
use Illuminate\Console\Command;

/**
 * Expires PENDING external transfers past their 7-day window.
 *
 * Scheduled every 15 minutes. Without it a request sits PENDING forever: the
 * sender never learns their money was not committed, and ledger invariant 6 in
 * `docs/01-DATABASE-SCHEMA.md` §8 fails.
 */
final class ExpireExternalTransfers extends Command
{
    protected $signature = 'transfers:expire-stale';

    protected $description = 'Expire external transfer requests that were never answered';

    public function handle(ExternalTransferService $transfers): int
    {
        $count = $transfers->expireStale();

        $this->info("Expired {$count} stale external transfer request(s).");

        return self::SUCCESS;
    }
}
