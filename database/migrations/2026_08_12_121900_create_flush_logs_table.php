<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flush audit — `docs/00-MASTER-PLAN.md` §4.9.
 *
 * IMMUTABLE. Never soft-deleted, never purged. If a user ever disputes what
 * happened to their data, this row is the answer.
 *
 * Deliberately no `updated_at`: nothing about a flush is ever amended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flush_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // SET NULL rather than RESTRICT: exports expire and get cleaned up,
            // and the cleanup job must not be blocked by an immutable log row.
            $table->foreignId('export_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('entries_deleted')->default(0);
            $table->unsignedInteger('balances_deleted')->default(0);
            $table->unsignedInteger('accounts_affected')->default(0);
            $table->decimal('total_balance_carried', 18, 4)->default(0);

            // Per-account closing balances at the moment of the flush.
            $table->json('snapshot')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->unique('uuid', 'uk_flush_logs_uuid');
            $table->index(['user_id', 'created_at'], 'idx_flush_logs_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flush_logs');
    }
};
