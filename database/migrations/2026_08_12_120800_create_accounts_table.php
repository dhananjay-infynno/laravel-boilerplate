<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger accounts — `docs/01-DATABASE-SCHEMA.md` §2.
 *
 * `account_number` is 6 characters and globally unique — a user in Mumbai must
 * be able to send to a user in Delhi by number alone. Never reused, so the
 * unique index deliberately covers soft-deleted rows too.
 *
 * Stored VARCHAR(10) rather than CHAR(6): GenerateAccountNumberAction widens to
 * 7 characters after repeated collisions, and a fallback the column cannot
 * store is worse than no fallback — it would fail under strict mode at exactly
 * the moment it was needed.
 *
 * `current_balance` is the denormalised running total and the read path for
 * every screen. It is only ever written inside a locked transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->string('account_number', 10);
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->char('currency_code', 3)->default('INR');

            $table->decimal('opening_balance', 18, 4)->default(0);
            $table->decimal('current_balance', 18, 4)->default(0);

            $table->boolean('is_main')->default(false);
            $table->boolean('allow_overdraft')->default(false);
            $table->string('status', 10)->default('active')->comment('See App\\Enums\\AccountStatus');
            $table->boolean('is_recalculating')->default(false);

            $table->smallInteger('sort_order')->unsigned()->default(0);
            $table->string('color', 7)->nullable();
            $table->string('icon', 40)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('uuid', 'uk_accounts_uuid');
            $table->unique('account_number', 'uk_accounts_number');
            $table->index(['user_id', 'status', 'deleted_at'], 'idx_accounts_user_status');
            $table->index(['user_id', 'is_main'], 'idx_accounts_user_main');
        });

        /*
         * "Exactly one main account per user" cannot be a plain unique index,
         * because many rows share is_main = 0.
         *
         * A generated column that is the user_id only for the live main account
         * (and NULL otherwise) plus a unique index does it at the database
         * level — MySQL treats NULLs as distinct, so every non-main row is
         * exempt. AccountService also guards this so the user gets a readable
         * error; this index is the backstop that makes it actually true.
         *
         * NOTE for the Account model: `main_flag` appears in SELECT * and must
         * never be in $fillable.
         */
        DB::statement('
            ALTER TABLE `accounts`
            ADD COLUMN `main_flag` BIGINT UNSIGNED
            GENERATED ALWAYS AS (
                CASE WHEN `is_main` = 1 AND `deleted_at` IS NULL THEN `user_id` ELSE NULL END
            ) VIRTUAL
        ');

        DB::statement('CREATE UNIQUE INDEX `uk_accounts_one_main` ON `accounts` (`main_flag`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
