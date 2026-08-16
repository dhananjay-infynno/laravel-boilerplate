<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * System categories — `user_id` NULL, shared by every user.
 *
 * Chosen for the Indian small-business / shopkeeper case rather than a generic
 * personal-finance list: Sales, Purchase and Party Payment matter far more here
 * than "Entertainment".
 */
class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            // Money in
            ['Sales', 'credit', 'shopping-cart', '#059669'],
            ['Customer Payment', 'credit', 'hand-coins', '#10b981'],
            ['Interest Received', 'credit', 'percent', '#14b8a6'],
            ['Loan Received', 'credit', 'landmark', '#0ea5e9'],
            ['Refund Received', 'credit', 'undo-2', '#22c55e'],
            ['Other Income', 'credit', 'circle-plus', '#84cc16'],

            // Money out
            ['Purchase', 'debit', 'package', '#e11d48'],
            ['Supplier Payment', 'debit', 'truck', '#f43f5e'],
            ['Salary & Wages', 'debit', 'users', '#ef4444'],
            ['Rent', 'debit', 'home', '#f97316'],
            ['Utilities', 'debit', 'zap', '#f59e0b'],
            ['Transport & Fuel', 'debit', 'fuel', '#eab308'],
            ['Food & Tea', 'debit', 'coffee', '#d97706'],
            ['Repairs & Maintenance', 'debit', 'wrench', '#dc2626'],
            ['Loan Repayment', 'debit', 'banknote', '#b91c1c'],
            ['Interest Paid', 'debit', 'trending-down', '#be123c'],
            ['Tax & GST', 'debit', 'receipt', '#9f1239'],
            ['Bank Charges', 'debit', 'credit-card', '#7c2d12'],
            ['Rent Advance', 'debit', 'key', '#a16207'],
            ['Marketing', 'debit', 'megaphone', '#c2410c'],
            ['Packaging', 'debit', 'box', '#ea580c'],
            ['Other Expense', 'debit', 'circle-minus', '#78716c'],

            // Either
            ['Cash Adjustment', 'both', 'scale', '#6366f1'],
            ['Opening Balance', 'both', 'flag', '#8b5cf6'],
            ['Transfer', 'both', 'arrow-left-right', '#3b82f6'],
        ];

        foreach ($categories as $index => [$name, $type, $icon, $color]) {
            Category::updateOrCreate(
                ['user_id' => null, 'name' => $name, 'deleted_at' => null],
                [
                    'type' => $type,
                    'icon' => $icon,
                    'color' => $color,
                    'is_system' => true,
                    'sort_order' => $index,
                ],
            );
        }
    }
}
