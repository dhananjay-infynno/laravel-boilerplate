<?php

declare(strict_types=1);

namespace App\Enums;

enum EntryDirection: string
{
    case In = 'IN';
    case Out = 'OUT';

    public function label(): string
    {
        return match ($this) {
            self::In => 'In',
            self::Out => 'Out',
        };
    }

    /** +1 for money entering an account, -1 for money leaving it. */
    public function sign(): int
    {
        return match ($this) {
            self::In => 1,
            self::Out => -1,
        };
    }

    public function opposite(): self
    {
        return match ($this) {
            self::In => self::Out,
            self::Out => self::In,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
