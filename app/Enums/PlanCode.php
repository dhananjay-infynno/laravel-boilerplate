<?php

declare(strict_types=1);

namespace App\Enums;

enum PlanCode: string
{
    case Trial = 'trial';
    case BasicMonthly = 'basic_monthly';
    case BasicYearly = 'basic_yearly';
    case ProMonthly = 'pro_monthly';
    case ProYearly = 'pro_yearly';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Trial',
            self::BasicMonthly => 'Basic (Monthly)',
            self::BasicYearly => 'Basic (Yearly)',
            self::ProMonthly => 'Pro (Monthly)',
            self::ProYearly => 'Pro (Yearly)',
        };
    }

    /** Authoritative limits live in `plans.max_accounts`; this is the seed value. */
    public function maxAccounts(): int
    {
        return match ($this) {
            self::Trial, self::BasicMonthly, self::BasicYearly => 20,
            self::ProMonthly, self::ProYearly => 50,
        };
    }

    public function interval(): PlanInterval
    {
        return match ($this) {
            self::Trial => PlanInterval::None,
            self::BasicMonthly, self::ProMonthly => PlanInterval::Month,
            self::BasicYearly, self::ProYearly => PlanInterval::Year,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
