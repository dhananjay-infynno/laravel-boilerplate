<?php

use Illuminate\Support\Facades\Schedule;

// A PENDING transfer left un-swept means the sender never learns their money
// was never committed. Ledger invariant 6 in docs/01-DATABASE-SCHEMA.md §8.
Schedule::command('transfers:expire-stale')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('media:delete-temp-files')->daily();
Schedule::command('telescope:prune --hours=24')->daily();
Schedule::command('pulse:purge')->daily();

// To permanently delete soft-deleted records after X days
// Schedule::command('system:hard-delete-data')->daily();
