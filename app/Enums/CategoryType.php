<?php

declare(strict_types=1);

namespace App\Enums;

enum CategoryType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Credit',
            self::Debit => 'Debit',
            self::Both => 'Both',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
