<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sequences', function (Blueprint $table): void {
            // Gap-free per-user serial numbers. Allocation is
            //   UPDATE user_sequences SET entry_next_no = LAST_INSERT_ID(entry_next_no) + 1 WHERE user_id = ?
            // inside the same transaction as the insert. Never SELECT MAX(sr_no) + 1.
            $table->unsignedBigInteger('user_id')->primary();
            $table->unsignedBigInteger('entry_next_no')->default(100001);
            $table->unsignedBigInteger('balance_next_no')->default(100001);
            $table->timestamp('updated_at')->nullable();

            $table->foreign('user_id', 'fk_user_sequences_user')
                ->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sequences');
    }
};
