<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Database\Seeder;

/**
 * The five plans — `docs/03-BILLING.md` §1.
 *
 * `gateway_plan_id` is left null: it is filled by syncing with Razorpay, either
 * through the admin panel or `plans:sync-gateway`. Hardcoding a live gateway id
 * in a seeder is how staging ends up charging real cards.
 *
 * Prices are INR and GST-INCLUSIVE (see §7): a consumer product advertising
 * Rs 99 and charging Rs 116.82 loses conversions and generates refunds.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'trial',
                'name' => 'Free Trial',
                'description' => '30 days free, no card required.',
                'interval' => 'none',
                'interval_count' => 0,
                'max_accounts' => 20,
                'trial_days' => 30,
                'is_visible' => false,
                'sort_order' => 0,
                // No price row: a trial is never charged.
                'price' => null,
            ],
            [
                'code' => 'basic_monthly',
                'name' => 'Basic Monthly',
                'interval' => 'month',
                'interval_count' => 1,
                'max_accounts' => 20,
                'sort_order' => 1,
                'price' => '99.00',
            ],
            [
                'code' => 'basic_yearly',
                'name' => 'Basic Yearly',
                'description' => 'Two months free compared to monthly.',
                'interval' => 'year',
                'interval_count' => 1,
                'max_accounts' => 20,
                'sort_order' => 2,
                'price' => '999.00',
            ],
            [
                'code' => 'pro_monthly',
                'name' => 'Pro Monthly',
                'interval' => 'month',
                'interval_count' => 1,
                'max_accounts' => 50,
                'sort_order' => 3,
                'price' => '129.00',
            ],
            [
                'code' => 'pro_yearly',
                'name' => 'Pro Yearly',
                'description' => 'Best value.',
                'interval' => 'year',
                'interval_count' => 1,
                'max_accounts' => 50,
                'sort_order' => 4,
                'price' => '1299.00',
            ],
        ];

        foreach ($plans as $definition) {
            $price = $definition['price'];
            unset($definition['price']);

            $plan = Plan::updateOrCreate(
                ['code' => $definition['code']],
                array_merge($definition, [
                    'trial_days' => $definition['trial_days'] ?? 0,
                    'is_active' => true,
                    'is_visible' => $definition['is_visible'] ?? true,
                    'features' => [
                        'max_accounts' => $definition['max_accounts'],
                        'external_transfers' => true,
                        'attachments' => true,
                        'export_per_month' => 3,
                        'reports' => ['day', 'account', 'summary', 'category'],
                        'priority_support' => str_starts_with($definition['code'], 'pro_'),
                        'chat' => false,
                    ],
                ]),
            );

            if ($price !== null) {
                PlanPrice::updateOrCreate(
                    ['plan_id' => $plan->id, 'currency_code' => 'INR', 'gateway' => 'razorpay'],
                    ['amount' => $price, 'gateway_plan_id' => null, 'is_active' => true],
                );
            }
        }
    }
}
