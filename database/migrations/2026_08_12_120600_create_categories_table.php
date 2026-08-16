<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entry categories — `docs/01-DATABASE-SCHEMA.md` §2.
 *
 * `user_id` NULL means a system category available to everyone. Without
 * categories, reports can only say how much moved, never where it went, which
 * is the question users actually have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');

            // NULL = system category, seeded and shared by every user.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name', 60);
            $table->string('type', 10)->default('both')->comment('See App\\Enums\\CategoryType');
            $table->string('icon', 40)->nullable();
            $table->string('color', 7)->nullable();
            $table->boolean('is_system')->default(false);
            $table->smallInteger('sort_order')->unsigned()->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('uuid', 'uk_categories_uuid');
            $table->unique(['user_id', 'name', 'deleted_at'], 'uk_categories_user_name');
            $table->index(['user_id', 'type'], 'idx_categories_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
