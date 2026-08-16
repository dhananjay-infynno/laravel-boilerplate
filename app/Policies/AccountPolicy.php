<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

/**
 * Ownership, and nothing else.
 *
 * This is the single most important security control in the application.
 * Without it, any authenticated user can read or modify any other user's ledger
 * by guessing a UUID — the most common real-world API vulnerability there is.
 *
 * Every single-resource route calls authorize(), and every one has a test
 * proving user A gets a 403 on user B's account.
 */
final class AccountPolicy
{
    public function view(User $user, Account $account): bool
    {
        return $user->id === $account->user_id;
    }

    public function update(User $user, Account $account): bool
    {
        return $user->id === $account->user_id;
    }

    public function delete(User $user, Account $account): bool
    {
        return $user->id === $account->user_id;
    }

    public function setMain(User $user, Account $account): bool
    {
        return $user->id === $account->user_id;
    }
}
