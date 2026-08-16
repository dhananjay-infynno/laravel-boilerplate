<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * The ONLY place money arithmetic happens.
 *
 * Money is a DECIMAL(18,4) in MySQL, a `decimal:4` cast in Eloquent, and a
 * STRING everywhere else — including in JSON, so JavaScript cannot silently
 * lose precision on it.
 *
 * Every operation goes through bcmath at scale 4. `+`, `-`, `==`, `<` and `>`
 * on money are banned in this codebase: PHP floats cannot represent 0.1
 * exactly, and a ledger that is out by a hundredth of a paisa per transaction
 * is a ledger that does not reconcile.
 *
 * Requires ext-bcmath.
 */
final class Money
{
    /** Four decimal places — matches DECIMAL(18,4). */
    public const SCALE = 4;

    public const ZERO = '0.0000';

    public static function add(string $a, string $b): string
    {
        return bcadd(self::normalise($a), self::normalise($b), self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::normalise($a), self::normalise($b), self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul(self::normalise($a), self::normalise($b), self::SCALE);
    }

    public static function div(string $a, string $b): string
    {
        if (self::isZero($b)) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return bcdiv(self::normalise($a), self::normalise($b), self::SCALE);
    }

    /** -1 if $a < $b, 0 if equal, 1 if $a > $b. */
    public static function compare(string $a, string $b): int
    {
        return bccomp(self::normalise($a), self::normalise($b), self::SCALE);
    }

    public static function equals(string $a, string $b): bool
    {
        return self::compare($a, $b) === 0;
    }

    public static function isZero(string $value): bool
    {
        return self::compare($value, self::ZERO) === 0;
    }

    public static function isNegative(string $value): bool
    {
        return self::compare($value, self::ZERO) < 0;
    }

    public static function isPositive(string $value): bool
    {
        return self::compare($value, self::ZERO) > 0;
    }

    public static function abs(string $value): string
    {
        return self::isNegative($value) ? self::sub(self::ZERO, $value) : self::normalise($value);
    }

    public static function negate(string $value): string
    {
        return self::sub(self::ZERO, $value);
    }

    /**
     * Sum a list without ever touching a float.
     *
     * @param  iterable<string>  $values
     */
    public static function sum(iterable $values): string
    {
        $total = self::ZERO;

        foreach ($values as $value) {
            $total = self::add($total, (string) $value);
        }

        return $total;
    }

    /**
     * Canonical string form: always scale 4, always a valid decimal.
     *
     * Accepts what MySQL and Eloquent hand back ("1500", "1500.0000",
     * "1500.00") and normalises it. Rejects scientific notation and anything
     * non-numeric — `1e5` is not an amount a human typed, and silently
     * accepting it is how a rounding bug becomes a wire transfer.
     */
    public static function normalise(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return self::ZERO;
        }

        // A float arriving here is already suspect, but refusing it outright
        // would break Eloquent's own casts on some drivers. Convert with full
        // precision and let the regex below reject anything pathological.
        $string = is_float($value)
            ? number_format($value, self::SCALE, '.', '')
            : trim((string) $value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $string)) {
            throw new InvalidArgumentException("Invalid money value: {$string}");
        }

        return bcadd($string, '0', self::SCALE);
    }

    /**
     * Rupees to paise, as an integer.
     *
     * Razorpay expects paise. This is the ONLY place that conversion happens —
     * an inline `* 100` somewhere else is the classic first-day gateway bug,
     * and it is out by a factor of a hundred in the direction that charges
     * customers too much.
     */
    public static function toPaise(string $rupees): int
    {
        return (int) bcmul(self::normalise($rupees), '100', 0);
    }

    public static function fromPaise(int $paise): string
    {
        return bcdiv((string) $paise, '100', self::SCALE);
    }

    /**
     * Display formatting. Never used for storage or comparison.
     *
     * Indian grouping (1,00,000) is applied for `en-IN` because
     * number_format() only knows the Western pattern and would render
     * "100,000", which reads as wrong to every user in this market.
     */
    public static function format(string $value, int $decimals = 2, string $locale = 'en-IN'): string
    {
        $normalised = self::normalise($value);
        $negative = self::isNegative($normalised);
        $abs = self::abs($normalised);

        $rounded = bcadd($abs, '0', $decimals);
        [$whole, $fraction] = array_pad(explode('.', $rounded), 2, '');

        $grouped = $locale === 'en-IN'
            ? self::groupIndian($whole)
            : number_format((float) $whole, 0, '.', ',');

        $result = $fraction !== '' ? "{$grouped}.{$fraction}" : $grouped;

        return $negative ? "-{$result}" : $result;
    }

    /** 1234567 -> 12,34,567. Last three digits, then pairs. */
    private static function groupIndian(string $whole): string
    {
        if (strlen($whole) <= 3) {
            return $whole;
        }

        $last3 = substr($whole, -3);
        $rest = substr($whole, 0, -3);

        return preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest).','.$last3;
    }
}
