<?php

use Illuminate\Support\Facades\Schedule;

// A PENDING transfer left un-swept means the sender never learns their money
// was never committed. Ledger invariant 6 in docs/01-DATABASE-SCHEMA.md §8.
Schedule::command('transfers:expire-stale')->everyFifteenMinutes()->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Billing
|--------------------------------------------------------------------------
|
| Times are IST (config/app.php timezone), chosen so the noisy jobs run when
| almost nobody is using the product.
|
| Every one of these is `withoutOverlapping()`. A reconcile sweep that takes
| longer than an hour would otherwise stack a second copy on top of the first
| and both would fight over the same rows.
*/

// Hourly: catch dropped renewal webhooks quickly. Narrow by default — only
// subscriptions that look suspect.
Schedule::command('billing:reconcile')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Nightly full sweep. Slower and broader; the hourly pass is the fast path and
// this is the one that actually guarantees nothing drifts indefinitely.
Schedule::command('billing:reconcile --all --limit=5000')
    ->dailyAt('03:30')
    ->withoutOverlapping();

// Once a day only. Dunning stages are derived from the grace window rather
// than a counter, so running more often would send the same email repeatedly.
// 10:00 IST because a payment reminder that lands at 3am gets ignored.
Schedule::command('billing:dunning')->dailyAt('10:00')->withoutOverlapping();

// Trials have no gateway subscription, so nothing external ever tells us one
// ended — this command is the only thing that closes them.
Schedule::command('billing:expire-trials')->dailyAt('00:15')->withoutOverlapping();

Schedule::command('media:delete-temp-files')->daily();
Schedule::command('telescope:prune --hours=24')->daily();
Schedule::command('pulse:purge')->daily();

// To permanently delete soft-deleted records after X days
// Schedule::command('system:hard-delete-data')->daily();
