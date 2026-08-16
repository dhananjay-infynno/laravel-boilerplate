<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Circular foreign keys — `docs/01-DATABASE-SCHEMA.md` §6.
 *
 * These could not be declared at creation time because each side references a
 * table that did not exist yet:
 *
 *   user_settings.default_account_id  -> accounts   (accounts came later)
 *   entries.linked_entry_id           -> entries    (self-reference)
 *   entries.parent_entry_id           -> entries    (self-reference)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->foreign('default_account_id', 'fk_user_settings_default_account')
                ->references('id')->on('accounts')
                ->nullOnDelete();
        });

        Schema::table('entries', function (Blueprint $table) {
            // The mirror entry in the counterparty's book. SET NULL rather than
            // CASCADE: if one side is ever hard-deleted, the other must survive
            // as an orphan for investigation, not vanish silently.
            $table->foreign('linked_entry_id', 'fk_entries_linked')
                ->references('id')->on('entries')
                ->nullOnDelete();

            $table->foreign('parent_entry_id', 'fk_entries_parent')
                ->references('id')->on('entries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('entries', function (Blueprint $table) {
            $table->dropForeign('fk_entries_linked');
            $table->dropForeign('fk_entries_parent');
        });

        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropForeign('fk_user_settings_default_account');
        });
    }
};
