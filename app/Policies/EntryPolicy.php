<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Entry;
use App\Models\User;

/**
 * Ownership only.
 *
 * `user_id` is the owner of the BOOK the entry appears in, so an accepted
 * external transfer correctly grants each party access to their own side and
 * neither to the other's.
 */
final class EntryPolicy
{
    public function view(User $user, Entry $entry): bool
    {
        return $user->id === $entry->user_id;
    }

    public function update(User $user, Entry $entry): bool
    {
        return $user->id === $entry->user_id;
    }

    public function delete(User $user, Entry $entry): bool
    {
        return $user->id === $entry->user_id;
    }
}
