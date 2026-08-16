<?php

declare(strict_types=1);

namespace App\Enums;

enum EntryType: string
{
    case CreditEntry = 'CREDIT_ENTRY';
    case DebitEntry = 'DEBIT_ENTRY';
    case AccountToAccount = 'ACCOUNT_TO_ACCOUNT';
    case ExternalTransfer = 'EXTERNAL_TRANSFER';

    public function label(): string
    {
        return match ($this) {
            self::CreditEntry => 'Credit Entry',
            self::DebitEntry => 'Debit Entry',
            self::AccountToAccount => 'Account to Account',
            self::ExternalTransfer => 'External Transfer',
        };
    }

    /** The default direction carried by an entry of this type. */
    public function defaultDirection(): EntryDirection
    {
        return match ($this) {
            self::CreditEntry => EntryDirection::In,
            self::DebitEntry, self::AccountToAccount, self::ExternalTransfer => EntryDirection::Out,
        };
    }

    public function requiresFromAccount(): bool
    {
        return match ($this) {
            self::DebitEntry, self::AccountToAccount, self::ExternalTransfer => true,
            self::CreditEntry => false,
        };
    }

    public function requiresToAccount(): bool
    {
        return match ($this) {
            self::CreditEntry, self::AccountToAccount => true,
            self::DebitEntry, self::ExternalTransfer => false,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
