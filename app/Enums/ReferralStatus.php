<?php

declare(strict_types=1);

namespace App\Enums;

enum ReferralStatus: string
{
    case Pending = 'pending';
    case Qualified = 'qualified';
    case Rewarded = 'rewarded';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Qualified => 'Qualified',
            self::Rewarded => 'Rewarded',
            self::Expired => 'Expired',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
