<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger — `docs/01-DATABASE-SCHEMA.md` §2. The largest table by far.
 *
 * Column semantics by type:
 *   CREDIT_ENTRY       IN   to_account required                → to.balance += amount
 *   DEBIT_ENTRY        OUT  from_account required              → from.balance -= amount
 *   ACCOUNT_TO_ACCOUNT OUT  both required, both owned by user  → both, atomically
 *   EXTERNAL_TRANSFER  OUT  from + counterparty_*              → nothing until ACCEPTED
 *
 * `amount` is always POSITIVE; the sign lives in `direction`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');

            // Owner of the BOOK this entry appears in. An accepted external
            // transfer produces one row in each party's book.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->unsignedBigInteger('sr_no');
            $table->date('entry_date');
            $table->time('entry_time')->nullable();

            $table->string('type', 24)->comment('See App\\Enums\\EntryType');
            // Precomputed so reports never have to reason about the type.
            $table->string('direction', 3)->comment('See App\\Enums\\EntryDirection');

            $table->foreignId('from_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('accounts')->restrictOnDelete();

            $table->decimal('amount', 18, 4);
            $table->char('currency_code', 3)->default('INR');
            $table->string('status', 12)->default('COMPLETED')->comment('See App\\Enums\\EntryStatus');

            $table->string('remarks', 500)->nullable();
            $table->string('reference_no', 60)->nullable();

            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('party_id')->nullable()->constrained()->nullOnDelete();

            // External transfers.
            $table->foreignId('counterparty_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('counterparty_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedBigInteger('linked_entry_id')->nullable();
            $table->unsignedBigInteger('parent_entry_id')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();

            $table->char('idempotency_key', 36)->nullable();
            $table->unsignedTinyInteger('attachment_count')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('uuid', 'uk_entries_uuid');
            $table->unique(['user_id', 'sr_no'], 'uk_entries_user_srno');
            $table->unique(['user_id', 'idempotency_key'], 'uk_entries_idem');

            $table->index(['counterparty_user_id', 'status', 'expires_at'], 'idx_entries_pending');
            $table->index(['user_id', 'type', 'entry_date'], 'idx_entries_type');
            $table->index(['user_id', 'category_id', 'entry_date'], 'idx_entries_category');
            $table->index(['user_id', 'party_id', 'entry_date'], 'idx_entries_party');
        });

        /*
         * DESC indexes — Blueprint cannot express per-column direction.
         *
         * These lead with the filter columns and END with the sort columns, so
         * one index satisfies both WHERE and ORDER BY. That is what keeps
         * cursor pagination flat as this table grows into the tens of millions.
         *
         * Created here, after the table but before heavy use, so InnoDB adopts
         * them rather than building redundant duplicates for the foreign keys.
         */
        DB::statement('CREATE INDEX `idx_entries_user_date` ON `entries` (`user_id`, `deleted_at`, `entry_date` DESC, `id` DESC)');
        DB::statement('CREATE INDEX `idx_entries_from_date` ON `entries` (`from_account_id`, `deleted_at`, `entry_date` DESC, `id` DESC)');
        DB::statement('CREATE INDEX `idx_entries_to_date` ON `entries` (`to_account_id`, `deleted_at`, `entry_date` DESC, `id` DESC)');

        // Cheap insurance: no entry may exist for zero or a negative amount.
        DB::statement('ALTER TABLE `entries` ADD CONSTRAINT `ck_entries_amount_positive` CHECK (`amount` > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('entries');
    }
};
