<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\Account;
use RuntimeException;

/**
 * Generates the globally unique, human-quotable account number.
 *
 * Alphabet excludes 0/O and 1/I/L — every one of these gets read aloud over a
 * phone or copied off a handwritten note, and those pairs are where people make
 * mistakes. 31 symbols across 6 characters is ~887 million combinations.
 */
final class GenerateAccountNumberAction
{
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const MAX_LENGTH = 10;

    public function handle(int $length = 6, int $attempts = 5): string
    {
        if ($length > self::MAX_LENGTH) {
            // Unreachable at any plausible scale. If it ever fires, something
            // is badly wrong with random_int, not with the number space.
            throw new RuntimeException('Exhausted the account number space.');
        }

        for ($i = 0; $i < $attempts; $i++) {
            $number = $this->random($length);

            // withTrashed(): numbers are NEVER reused. A soft-deleted account
            // still owns its number, because someone may have saved it to send
            // a transfer.
            if (! Account::withTrashed()->where('account_number', $number)->exists()) {
                return $number;
            }
        }

        // Widen rather than fail. The column is VARCHAR(10) precisely so this
        // fallback can actually be stored.
        return $this->handle($length + 1, $attempts);
    }

    private function random(int $length): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            // random_int, not rand: account numbers are guessable targets for
            // the external-transfer lookup.
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }
}
