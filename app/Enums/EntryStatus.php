<?php

declare(strict_types=1);

namespace App\Enums;

enum EntryStatus: string
{
    case Completed = 'COMPLETED';
    case Pending = 'PENDING';
    case Accepted = 'ACCEPTED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
            self::Pending => 'Pending',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    /** Terminal states never transition again. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Accepted, self::Rejected, self::Cancelled, self::Expired => true,
            self::Pending => false,
        };
    }

    /** Only these states have actually moved money. */
    public function movesMoney(): bool
    {
        return match ($this) {
            self::Completed, self::Accepted => true,
            self::Pending, self::Rejected, self::Cancelled, self::Expired => false,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
