<?php

declare(strict_types=1);

namespace App\Enums;

enum PlanInterval: string
{
    case Month = 'month';
    case Year = 'year';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Month => 'Monthly',
            self::Year => 'Yearly',
            self::None => 'One-off',
        };
    }

    /** Days added per interval_count, used by the trial/period helpers. */
    public function days(): int
    {
        return match ($this) {
            self::Month => 30,
            self::Year => 365,
            self::None => 0,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
