<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\Domain\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * The in-app lock code — `docs/00-MASTER-PLAN.md` §4.8.
 *
 * A convenience lock over an already-authenticated session, not an
 * authentication factor. It still guards a financial ledger, so:
 *
 *   - bcrypt-hashed, never stored or logged in the clear
 *   - trivial codes refused
 *   - setting or removing requires the ACCOUNT password, so someone who picks
 *     up an unlocked phone cannot quietly change the lock
 *   - verification is throttled at the route (5/min). THAT is the real control
 *     — a 4-digit code on an unthrottled endpoint falls in minutes.
 */
final readonly class PinService
{
    /** Sequences and repeats that offer no protection at all. */
    private const FORBIDDEN = [
        '0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999',
        '1234', '4321', '0123', '9876', '1122', '1212',
        '000000', '111111', '123456', '654321', '123123', '112233', '121212',
    ];

    public function set(User $user, string $pin, string $currentPassword): void
    {
        $this->assertPassword($user, $currentPassword);

        $user->forceFill([
            'app_pin_hash' => Hash::make($pin),
            'pin_enabled' => true,
        ])->save();
    }

    public function verify(User $user, string $pin): bool
    {
        if (! is_string($user->app_pin_hash) || $user->app_pin_hash === '') {
            return false;
        }

        return Hash::check($pin, $user->app_pin_hash);
    }

    public function remove(User $user, string $currentPassword): void
    {
        $this->assertPassword($user, $currentPassword);

        $user->forceFill([
            'app_pin_hash' => null,
            'pin_enabled' => false,
        ])->save();
    }

    public static function isForbidden(string $pin): bool
    {
        return in_array($pin, self::FORBIDDEN, true);
    }

    private function assertPassword(User $user, string $password): void
    {
        if (! Hash::check($password, (string) $user->password)) {
            throw new InvalidCredentialsException;
        }
    }
}
