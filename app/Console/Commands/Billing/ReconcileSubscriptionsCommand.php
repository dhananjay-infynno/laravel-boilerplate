<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-syncs local subscription state against the gateway.
 *
 * WHY THIS IS NOT OPTIONAL:
 *
 * Webhooks get dropped. Deploys restart workers mid-job. A signature check
 * fails because someone rotated a secret and forgot one environment. Every one
 * of those leaves a user either paying for access they no longer have, or
 * holding access they stopped paying for — and NOBODY notices, because the
 * failure is silent on both sides.
 *
 * This is the safety net. It should mostly find nothing; a run that repairs
 * drift regularly means the webhook path has a real bug worth chasing.
 *
 * Two cadences:
 *   hourly — subscriptions with a recent failed webhook or a lapsed period
 *   daily  — everything non-terminal
 */
final class ReconcileSubscriptionsCommand extends Command
{
    protected $signature = 'billing:reconcile
                            {--all : Check every non-terminal subscription rather than only suspect ones}
                            {--limit=500 : Maximum subscriptions to check in one run}';

    protected $description = 'Re-sync subscription state from the payment gateway';

    public function handle(SubscriptionService $subscriptions): int
    {
        $checked = 0;
        $repaired = 0;
        $failed = 0;

        $query = Subscription::query()
            ->nonTerminal()
            ->whereNotNull('gateway_subscription_id');

        if (! (bool) $this->option('all')) {
            /*
             * The narrow hourly sweep: a subscription whose period has already
             * ended but which still says active is exactly the shape of a
             * dropped renewal webhook.
             */
            $query->where(function ($q): void {
                $q->whereNotNull('current_period_end')
                    ->where('current_period_end', '<', now()->timestamp)
                    ->orWhereNull('current_period_end');
            });
        }

        $query->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->chunkById(100, function ($batch) use ($subscriptions, &$checked, &$repaired, &$failed): void {
                foreach ($batch as $subscription) {
                    $checked++;

                    try {
                        if ($subscriptions->reconcile($subscription)) {
                            $repaired++;
                        }
                    } catch (Throwable $e) {
                        // One unreachable subscription must not abort the sweep
                        // — the remaining ones are the whole point of the run.
                        $failed++;

                        Log::warning('Reconcile failed for one subscription.', [
                            'subscription_id' => $subscription->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info("Reconcile complete — checked: {$checked}, repaired: {$repaired}, failed: {$failed}");

        if ($repaired > 0) {
            // Worth an alert, not just a log line: repeated drift means the
            // webhook path is broken and the safety net is carrying the product.
            Log::warning('Subscription drift repaired by reconciliation.', [
                'checked' => $checked,
                'repaired' => $repaired,
            ]);
        }

        return self::SUCCESS;
    }
}
