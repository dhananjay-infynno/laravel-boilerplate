<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free, per-user serial numbers for entries and balance rows.
 *
 * WHY THIS EXISTS AT ALL:
 *
 *   SELECT MAX(sr_no) + 1 FROM entries WHERE user_id = ?
 *
 * is the obvious implementation and it is wrong. Two concurrent entry
 * creations both read the same MAX and both write the same sr_no — the unique
 * index then rejects one of them, and the user's entry silently fails under
 * exactly the load where it matters most.
 *
 * `UPDATE ... SET n = LAST_INSERT_ID(n) + 1` is atomic at the row level: MySQL
 * serialises the two updates, each caller gets a distinct value back through
 * LAST_INSERT_ID() on its OWN connection, and no application-level locking is
 * needed.
 *
 * Must be called INSIDE the same transaction as the row it numbers, so a
 * rolled-back entry does not burn a serial. (It will still leave the counter
 * advanced on rollback — MySQL cannot undo LAST_INSERT_ID — which is why the
 * numbers are gap-free in practice but not guaranteed gapless after a failure.
 * That is the right trade: a gap is harmless, a duplicate is not.)
 */
final readonly class UserSequenceService
{
    public function nextEntryNo(int $userId): int
    {
        return $this->next($userId, 'entry_next_no');
    }

    public function nextBalanceNo(int $userId): int
    {
        return $this->next($userId, 'balance_next_no');
    }

    /**
     * Allocate the next value for one column.
     *
     * The row is created by UserObserver at registration. The firstOrCreate
     * fallback covers users that predate it — without it, a legacy account's
     * first entry would fail on a missing row inside a locked transaction.
     */
    private function next(int $userId, string $column): int
    {
        $this->ensureRowExists($userId);

        $updated = DB::update(
            "UPDATE `user_sequences`
             SET `{$column}` = LAST_INSERT_ID(`{$column}`) + 1, `updated_at` = ?
             WHERE `user_id` = ?",
            [now(), $userId],
        );

        if ($updated === 0) {
            // Lost a race with a concurrent create, or the row vanished.
            // Re-create and retry once rather than fail the whole entry.
            $this->ensureRowExists($userId, force: true);

            DB::update(
                "UPDATE `user_sequences`
                 SET `{$column}` = LAST_INSERT_ID(`{$column}`) + 1, `updated_at` = ?
                 WHERE `user_id` = ?",
                [now(), $userId],
            );
        }

        // LAST_INSERT_ID() is per-connection, so this is OUR value even with
        // hundreds of concurrent writers.
        return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS id')->id;
    }

    private function ensureRowExists(int $userId, bool $force = false): void
    {
        if (! $force) {
            $exists = DB::table('user_sequences')->where('user_id', $userId)->exists();

            if ($exists) {
                return;
            }
        }

        // Serials start at 100001 so the first is already 6 digits and stays
        // human-quotable ("show me entry 100043").
        DB::table('user_sequences')->insertOrIgnore([
            'user_id' => $userId,
            'entry_next_no' => 100001,
            'balance_next_no' => 100001,
            'updated_at' => now(),
        ]);
    }
}
