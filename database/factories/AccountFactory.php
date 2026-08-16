<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Account>
 */
final class AccountFactory extends Factory
{
    protected $model = Account::class;

    /** No 0/O/1/I/L — must match GenerateAccountNumberAction. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Money as a STRING even in factories: a float here would defeat the
        // precision tests that exist to catch exactly that mistake.
        $opening = number_format((float) fake()->numberBetween(0, 100000), 4, '.', '');

        return [
            'user_id' => User::factory(),
            'account_number' => $this->accountNumber(),
            'name' => fake()->randomElement(['Cash', 'Bank', 'Shop', 'Petty Cash', 'UPI']).' '.fake()->numberBetween(1, 99),
            'description' => fake()->optional()->sentence(),
            'currency_code' => 'INR',
            'opening_balance' => $opening,
            'current_balance' => $opening,
            'is_main' => false,
            'allow_overdraft' => false,
            'status' => AccountStatus::Active,
            'is_recalculating' => false,
            'sort_order' => 0,
        ];
    }

    public function main(): static
    {
        return $this->state(fn (): array => ['is_main' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => AccountStatus::Inactive]);
    }

    public function withOverdraft(): static
    {
        return $this->state(fn (): array => ['allow_overdraft' => true]);
    }

    public function balance(string $amount): static
    {
        return $this->state(fn (): array => [
            'opening_balance' => $amount,
            'current_balance' => $amount,
        ]);
    }

    private function accountNumber(): string
    {
        $max = strlen(self::ALPHABET) - 1;

        return collect(range(1, 6))
            ->map(fn (): string => self::ALPHABET[random_int(0, $max)])
            ->implode('').Str::upper(Str::random(0));
    }
}
