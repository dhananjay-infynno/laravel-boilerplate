<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin audit — `docs/01-DATABASE-SCHEMA.md` §4.
 *
 * Every admin WRITE and every READ of a user's ledger. Support staff looking at
 * customer financial data must leave a trace — it is both a control and a
 * deterrent, and staff should be told it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();

            $table->string('action', 60);
            $table->string('auditable_type', 120)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('reason', 255)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->unique('uuid', 'uk_audit_uuid');
            $table->index(['admin_id', 'created_at'], 'idx_audit_admin');
            $table->index(['auditable_type', 'auditable_id'], 'idx_audit_target');
            $table->index('action', 'idx_audit_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};
