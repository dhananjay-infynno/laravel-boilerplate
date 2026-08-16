<?php

declare(strict_types=1);

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Trialing',
            self::Active => 'Active',
            self::PastDue => 'Past due',
            self::Paused => 'Paused',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    /** A user may hold at most one non-terminal subscription at a time. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cancelled, self::Expired => true,
            self::Trialing, self::Active, self::PastDue, self::Paused => false,
        };
    }

    /**
     * Whether the ledger is writable in this state.
     *
     * `past_due` deliberately fails open — see 03-BILLING.md §5. A transient UPI
     * failure must not lock a paying customer out of their own book.
     */
    public function canWrite(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::PastDue => true,
            self::Paused, self::Cancelled, self::Expired => false,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<int, self> */
    public static function nonTerminal(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $s): bool => ! $s->isTerminal()));
    }
}
