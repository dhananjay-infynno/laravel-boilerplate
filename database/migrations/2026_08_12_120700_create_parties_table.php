<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Party / contact ledger — `docs/01-DATABASE-SCHEMA.md` §2.
 *
 * "Who owes me money" is the feature that made Khatabook and OkCredit huge in
 * this market. `current_balance` positive means they owe the user.
 *
 * Treated as a ledger table (RESTRICT, not CASCADE) because it carries money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->string('name', 120);
            $table->string('phone', 20)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('type', 12)->default('customer')->comment('See App\\Enums\\PartyType');

            $table->decimal('opening_balance', 18, 4)->default(0);
            $table->decimal('current_balance', 18, 4)->default(0);

            $table->string('notes', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('uuid', 'uk_parties_uuid');
            $table->index(['user_id', 'deleted_at'], 'idx_parties_user');
            $table->index(['user_id', 'phone'], 'idx_parties_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
