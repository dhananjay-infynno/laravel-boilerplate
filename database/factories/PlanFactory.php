<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
final class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'interval' => 'month',
            'interval_count' => 1,
            'max_accounts' => 20,
            'trial_days' => 0,
            'features' => ['external_transfers' => true, 'attachments' => true],
            'is_active' => true,
            'is_visible' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    /** The free trial pseudo-plan: 30 days, 20 accounts, never billed. */
    public function trial(): static
    {
        return $this->state(fn (): array => [
            'code' => 'trial',
            'name' => 'Free Trial',
            'interval' => 'none',
            'interval_count' => 0,
            'max_accounts' => 20,
            'trial_days' => 30,
            'is_visible' => false,
        ]);
    }

    public function basicMonthly(): static
    {
        return $this->state(fn (): array => [
            'code' => 'basic_monthly',
            'name' => 'Basic Monthly',
            'max_accounts' => 20,
        ]);
    }

    public function proYearly(): static
    {
        return $this->state(fn (): array => [
            'code' => 'pro_yearly',
            'name' => 'Pro Yearly',
            'interval' => 'year',
            'max_accounts' => 50,
        ]);
    }

    public function maxAccounts(int $limit): static
    {
        return $this->state(fn (): array => ['max_accounts' => $limit]);
    }
}
