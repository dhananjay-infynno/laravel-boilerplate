<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency — `docs/01-DATABASE-SCHEMA.md` §4.
 *
 * The cache is the fast path (24h TTL); this table is the durable fallback and
 * the audit record. Purged after 7 days.
 *
 * `request_hash` catches a client reusing a key with a DIFFERENT body — that is
 * a client bug and must return 422, not replay the old response. Silently
 * replaying would hide a double-spend attempt.
 *
 * `key` is a MySQL reserved word; Blueprint backticks it, raw SQL must too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->char('key', 36);
            $table->string('endpoint', 120);
            $table->char('request_hash', 64);

            $table->unsignedSmallInteger('response_code')->nullable();
            $table->json('response_body')->nullable();
            $table->string('status', 12)->default('processing')->comment('processing | completed');

            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key'], 'uk_idem_user_key');
            $table->index('expires_at', 'idx_idem_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
