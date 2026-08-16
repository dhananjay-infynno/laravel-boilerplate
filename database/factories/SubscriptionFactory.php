<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Subscription>
 */
final class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_id' => Plan::factory(),
            'plan_price_id' => null,
            'gateway' => null,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => Carbon::now(),
            'current_period_end' => Carbon::now()->addMonth(),
        ];
    }

    /**
     * A trial: no gateway, no price row, never charged.
     *
     * This is the state that broke the schema first time round — `gateway` was
     * NOT NULL, and a trial genuinely has none.
     */
    public function trialing(int $days = 30): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::Trialing,
            'gateway' => null,
            'plan_price_id' => null,
            'trial_ends_at' => Carbon::now()->addDays($days),
            'current_period_start' => Carbon::now(),
            'current_period_end' => Carbon::now()->addDays($days),
        ]);
    }

    public function pastDue(): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::PastDue,
            'gateway' => 'razorpay',
            'failed_payment_count' => 1,
            'grace_ends_at' => Carbon::now()->addDays(7),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::Expired,
            'current_period_end' => Carbon::now()->subDay(),
            'ends_at' => Carbon::now()->subDay(),
        ]);
    }
}
