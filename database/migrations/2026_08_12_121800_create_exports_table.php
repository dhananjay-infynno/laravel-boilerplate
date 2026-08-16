<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data exports — `docs/00-MASTER-PLAN.md` §4.9.
 *
 * No password columns: exports are plain XLSX. Protection is the private
 * bucket, a 5-minute signed URL bound to the requesting user, and a 7-day
 * expiry after which a scheduled job deletes the object. The file is never
 * emailed as an attachment.
 *
 * `file_hash` lets support confirm which file a user actually downloaded, and
 * catches a truncated upload BEFORE they flush their data on the strength of a
 * corrupt export.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('type', 20)->default('full')->comment('full | account | date_range');
            $table->string('format', 10)->default('xlsx');
            $table->json('filters')->nullable();

            $table->string('status', 20)->default('queued')->comment('See App\\Enums\\ExportStatus');

            $table->string('file_path', 255)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('file_hash', 64)->nullable();
            $table->unsignedInteger('row_count')->nullable();

            $table->timestamp('expires_at')->nullable();
            // Flush stays locked until this is set — a user must have the file
            // in hand before their ledger is wiped.
            $table->timestamp('downloaded_at')->nullable();
            $table->unsignedSmallInteger('download_count')->default(0);

            $table->text('error')->nullable();

            $table->boolean('flush_requested')->default(false);
            $table->timestamp('flushed_at')->nullable();

            $table->timestamps();

            $table->unique('uuid', 'uk_exports_uuid');
            $table->index(['user_id', 'status'], 'idx_exports_user_status');
            $table->index('expires_at', 'idx_exports_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
