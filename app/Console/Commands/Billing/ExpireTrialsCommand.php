<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Notifications\Billing\TrialEndingSoon;
use App\Services\EntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Ends lapsed trials and warns the ones about to lapse.
 *
 * A trial has NO gateway subscription, so nothing external ever tells us it
 * ended — this command is the only thing that closes it. Without it a trial
 * user keeps write access forever, which is a bug that costs revenue quietly
 * for months before anyone spots it.
 *
 * Reminders go out at 7 days and 1 day. The 1-day one matters most: a month is
 * long enough that people genuinely forget they signed up, and a silent
 * lock-out on day 31 reads as the product breaking rather than the trial ending.
 */
final class ExpireTrialsCommand extends Command
{
    protected $signature = 'billing:expire-trials';

    protected $description = 'Expire finished trials and warn users whose trial is about to end';

    public function handle(EntitlementService $entitlements): int
    {
        $now = CarbonImmutable::now();
        $expired = 0;
        $warned = 0;

        Subscription::query()
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNull('gateway_subscription_id')
            ->whereNotNull('trial_ends_at')
            ->with('user')
            ->chunkById(200, function ($batch) use ($now, $entitlements, &$expired, &$warned): void {
                foreach ($batch as $subscription) {
                    $endsAt = CarbonImmutable::createFromTimestamp((int) $subscription->trial_ends_at);

                    if ($now->greaterThanOrEqualTo($endsAt)) {
                        $subscription->update([
                            'status' => SubscriptionStatus::Expired,
                            'ends_at' => $endsAt,
                        ]);

                        $entitlements->forget((int) $subscription->user_id);
                        $expired++;

                        continue;
                    }

                    $daysLeft = (int) $now->diffInDays($endsAt, false);

                    if (! in_array($daysLeft, [7, 1], true)) {
                        continue;
                    }

                    // Guarded on the day value, so a second run on the same day
                    // does not send the same warning twice.
                    if ((int) data_get($subscription->metadata, 'trial_warned_at_days') === $daysLeft) {
                        continue;
                    }

                    $subscription->user?->notify(new TrialEndingSoon($daysLeft, $endsAt));

                    $subscription->update([
                        'metadata' => array_merge((array) $subscription->metadata, [
                            'trial_warned_at_days' => $daysLeft,
                        ]),
                    ]);

                    $warned++;
                }
            });

        $this->info("Trials — expired: {$expired}, warned: {$warned}");

        return self::SUCCESS;
    }
}
