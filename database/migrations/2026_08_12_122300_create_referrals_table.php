<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referrals — `docs/00-MASTER-PLAN.md` §10, recommendation 4.
 *
 * At Rs 99/month the paid-acquisition maths does not work; referral is the only
 * channel that survives it.
 *
 * "Qualified" must mean the referred user PAID, not merely signed up —
 * otherwise the trial-farming problem simply becomes a rewarded trial-farming
 * problem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');

            $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();

            $table->string('code', 12);
            $table->string('status', 12)->default('pending')->comment('See App\\Enums\\ReferralStatus');
            $table->unsignedSmallInteger('reward_days')->default(30);

            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();

            $table->timestamps();

            $table->unique('uuid', 'uk_referrals_uuid');
            // One referral per referred user, ever.
            $table->unique('referred_user_id', 'uk_referrals_referred');
            $table->index(['referrer_user_id', 'status'], 'idx_referrals_referrer');
            $table->index('code', 'idx_referrals_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
