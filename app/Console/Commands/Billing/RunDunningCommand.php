<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Notifications\Billing\DunningReminder;
use App\Services\EntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Walks past_due subscriptions and nudges, then suspends.
 *
 * Runs ONCE A DAY, and the stage is derived from how far into the grace window
 * the subscription is rather than from a counter. That makes the command safe
 * to run twice — a second run on the same day computes the same stage and the
 * `last_dunning_stage` check swallows it. A counter would double-send.
 *
 * The schedule across a 7-day grace window:
 *
 *   day 0   PaymentFailedNotice, sent by the webhook handler, not here
 *   day 2   second   — "still pending, your account works normally"
 *   day 5   final    — "access pauses on <date>"
 *   day 7+  suspended — status flips to expired, access becomes read-only
 *
 * Note what "suspended" does NOT mean: nothing is deleted, and the ledger stays
 * fully readable and exportable. A user who stops paying keeps their financial
 * records — holding someone's own bookkeeping hostage is both wrong and, for a
 * product people trust with money, commercially suicidal.
 */
final class RunDunningCommand extends Command
{
    protected $signature = 'billing:dunning {--dry-run : Report what would be sent without sending}';

    protected $description = 'Send dunning reminders and suspend subscriptions past their grace period';

    public function handle(EntitlementService $entitlements): int
    {
        $now = CarbonImmutable::now();
        $dryRun = (bool) $this->option('dry-run');
        $counts = ['second' => 0, 'final' => 0, 'suspended' => 0];

        Subscription::query()
            ->where('status', SubscriptionStatus::PastDue)
            ->whereNotNull('grace_ends_at')
            ->with('user')
            ->chunkById(200, function ($subscriptions) use ($now, $dryRun, &$counts, $entitlements): void {
                foreach ($subscriptions as $subscription) {
                    $stage = $this->stageFor($subscription, $now);

                    if ($stage === null) {
                        continue;
                    }

                    // Already sent this stage. The guard is what makes a second
                    // run in the same day harmless.
                    if ((string) data_get($subscription->metadata, 'last_dunning_stage') === $stage) {
                        continue;
                    }

                    $counts[$stage]++;

                    if ($dryRun) {
                        $this->line("would send [{$stage}] to subscription {$subscription->id}");

                        continue;
                    }

                    if ($stage === 'suspended') {
                        $subscription->update(['status' => SubscriptionStatus::Expired]);
                        // Without this the user keeps write access for up to
                        // 15 minutes after being suspended.
                        $entitlements->forget((int) $subscription->user_id);

                        Log::info('Subscription suspended after grace period.', [
                            'subscription_id' => $subscription->id,
                        ]);
                    }

                    $subscription->user?->notify(new DunningReminder($subscription, $stage));

                    $subscription->update([
                        'metadata' => array_merge((array) $subscription->metadata, [
                            'last_dunning_stage' => $stage,
                            'last_dunning_at' => $now->toIso8601String(),
                        ]),
                    ]);
                }
            });

        $this->info(sprintf(
            '%sDunning complete — second: %d, final: %d, suspended: %d',
            $dryRun ? '[dry run] ' : '',
            $counts['second'],
            $counts['final'],
            $counts['suspended'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return 'second'|'final'|'suspended'|null
     */
    private function stageFor(Subscription $subscription, CarbonImmutable $now): ?string
    {
        $graceEnds = $subscription->grace_ends_at;

        if ($graceEnds === null) {
            return null;
        }

        $ends = CarbonImmutable::createFromTimestamp((int) $graceEnds);

        if ($now->greaterThanOrEqualTo($ends)) {
            return 'suspended';
        }

        $graceDays = (int) config('razorpay.grace_days', 7);
        $elapsed = $graceDays - (int) $now->diffInDays($ends, false);

        return match (true) {
            $elapsed >= 5 => 'final',
            $elapsed >= 2 => 'second',
            default => null,
        };
    }
}
