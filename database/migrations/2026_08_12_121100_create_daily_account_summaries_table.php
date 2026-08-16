<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reporting rollup — `docs/01-DATABASE-SCHEMA.md` §2.
 *
 * A CACHE, fully derivable from entry_balances and rebuildable at any time.
 * It exists because day reports and dashboard charts would otherwise scan the
 * entries table on every request — the single biggest performance decision in
 * the system.
 *
 * Reports read ONLY from here. Never sum entries at request time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_account_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->date('summary_date');

            $table->decimal('opening_balance', 18, 4);
            $table->decimal('closing_balance', 18, 4);
            $table->decimal('total_credit', 18, 4)->default(0);
            $table->decimal('total_debit', 18, 4)->default(0);

            $table->unsignedInteger('entry_count')->default(0);
            $table->unsignedInteger('credit_count')->default(0);
            $table->unsignedInteger('debit_count')->default(0);

            $table->timestamps();

            // One row per account per day — the key the refresh upserts on.
            $table->unique(['account_id', 'summary_date'], 'uk_das');
            $table->index(['user_id', 'summary_date'], 'idx_das_user_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_account_summaries');
    }
};
