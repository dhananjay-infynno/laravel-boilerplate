<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription plans — `docs/03-BILLING.md` §1.
 *
 * Limits live in `max_accounts` and the feature matrix in `features` JSON, so
 * adding a tier is a database row rather than a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');

            // trial | basic_monthly | basic_yearly | pro_monthly | pro_yearly
            $table->string('code', 40);
            $table->string('name', 80);
            $table->string('description', 255)->nullable();

            // `interval` is a MySQL reserved word — Blueprint backticks it, but
            // any raw SQL touching this column must too.
            $table->string('interval', 10)->default('month')->comment('See App\\Enums\\PlanInterval');
            $table->unsignedSmallInteger('interval_count')->default(1);

            $table->unsignedSmallInteger('max_accounts')->default(20);
            $table->unsignedSmallInteger('trial_days')->default(0);

            $table->json('features')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_visible')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique('uuid', 'uk_plans_uuid');
            $table->unique('code', 'uk_plans_code');
            $table->index(['is_active', 'is_visible'], 'idx_plans_visible');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
