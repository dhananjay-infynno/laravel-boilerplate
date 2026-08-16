<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use App\Models\UserSequence;
use App\Models\UserSetting;

/**
 * Guarantees the two rows every user must have.
 *
 * Both are created here rather than lazily on first use so that nothing
 * downstream has to null-check:
 *
 *   - `user_settings` is read on every login payload and by every money format
 *   - `user_sequences` is read inside EntryService's transaction; creating it
 *     lazily there would mean a row INSERT inside a locked path, and two
 *     concurrent first-entries racing to create it
 */
final class UserObserver
{
    public function created(User $user): void
    {
        UserSetting::firstOrCreate(
            ['user_id' => $user->id],
            [
                'decimal_places' => 2,
                'theme' => 'system',
                'language' => $user->locale ?? config('app.locale', 'en'),
                'show_print_option' => true,
                'allow_external_transfers' => true,
            ],
        );

        // Serial numbers start at 100001 so the first entry is already 6 digits
        // and stays human-quotable ("show me entry 100043").
        UserSequence::firstOrCreate(
            ['user_id' => $user->id],
            ['entry_next_no' => 100001, 'balance_next_no' => 100001],
        );
    }
}
