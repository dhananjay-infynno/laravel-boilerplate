<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The immutable audit trail — `docs/01-DATABASE-SCHEMA.md` §2.
 *
 * One row per (entry x affected account). An account-to-account transfer is ONE
 * entry with TWO rows here.
 *
 * APPEND-ONLY in normal operation. This is what an account statement reads and
 * what RecalculateAccountBalancesJob replays after a deletion. A deleted entry
 * flags its rows `is_reversed`; it never removes them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_balances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('sr_no');

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('entry_id')->constrained()->restrictOnDelete();

            // Denormalised from the entry so a statement never joins to read a date.
            $table->date('entry_date');
            $table->string('direction', 3)->comment('See App\\Enums\\EntryDirection');

            $table->decimal('amount', 18, 4);
            $table->decimal('opening_balance', 18, 4);
            $table->decimal('closing_balance', 18, 4);

            $table->boolean('is_reversed')->default(false);

            $table->timestamps();

            $table->unique('uuid', 'uk_eb_uuid');
            $table->unique(['entry_id', 'account_id'], 'uk_eb_entry_account');
        });

        DB::statement('CREATE INDEX `idx_eb_account_date` ON `entry_balances` (`account_id`, `entry_date` DESC, `id` DESC)');
        DB::statement('CREATE INDEX `idx_eb_user_date` ON `entry_balances` (`user_id`, `entry_date` DESC)');

        /*
         * Integrity check 3 from §8, enforced by the database rather than hoped for.
         *
         * Side effect worth knowing: a partial UPDATE touching only
         * closing_balance is rejected, so a recompute must rewrite opening and
         * closing together. RecalculateAccountBalancesJob does exactly that.
         */
        DB::statement("
            ALTER TABLE `entry_balances`
            ADD CONSTRAINT `ck_eb_closing_consistent` CHECK (
                (`direction` = 'IN'  AND `closing_balance` = `opening_balance` + `amount`) OR
                (`direction` = 'OUT' AND `closing_balance` = `opening_balance` - `amount`)
            )
        ");

        DB::statement('ALTER TABLE `entry_balances` ADD CONSTRAINT `ck_eb_amount_positive` CHECK (`amount` > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_balances');
    }
};
