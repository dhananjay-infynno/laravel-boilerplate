<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // NOTE: `country_code` (VARCHAR 32) and `last_login_at` already exist in the
            // boilerplate table. They are deliberately not re-added here.
            $table->char('uuid', 36)->nullable()->after('id');
            $table->char('currency_code', 3)->default('INR')->after('country_code');
            $table->string('timezone', 64)->default('Asia/Kolkata')->after('currency_code');
            $table->string('phone', 20)->nullable()->after('timezone');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->timestamp('trial_ends_at')->nullable()->after('phone_verified_at');
            $table->char('current_session_id', 36)->nullable()->after('trial_ends_at');
            $table->string('app_pin_hash', 255)->nullable()->after('current_session_id');
            $table->boolean('pin_enabled')->default(false)->after('app_pin_hash');
            $table->boolean('biometric_enabled')->default(false)->after('pin_enabled');
            $table->string('last_login_ip', 45)->nullable()->after('biometric_enabled');
            $table->string('registration_source', 20)->default('app')->after('last_login_ip');
            $table->unsignedBigInteger('referred_by_user_id')->nullable()->after('registration_source');
            $table->boolean('is_suspended')->default(false)->after('referred_by_user_id');
            $table->string('suspended_reason', 255)->nullable()->after('is_suspended');
        });

        // The table may already hold rows, so `uuid` is added nullable, backfilled,
        // and only then tightened to NOT NULL ahead of the unique index.
        DB::statement('UPDATE `users` SET `uuid` = UUID() WHERE `uuid` IS NULL');
        DB::statement('ALTER TABLE `users` MODIFY `uuid` CHAR(36) NOT NULL');

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('uuid', 'uk_users_uuid');
            $table->index('trial_ends_at', 'idx_users_trial_ends');
            $table->index('country_code', 'idx_users_country');
            $table->index('is_suspended', 'idx_users_suspended');

            $table->foreign('referred_by_user_id', 'fk_users_referred_by')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign('fk_users_referred_by');
            $table->dropUnique('uk_users_uuid');
            $table->dropIndex('idx_users_trial_ends');
            $table->dropIndex('idx_users_country');
            $table->dropIndex('idx_users_suspended');

            $table->dropColumn([
                'uuid',
                'currency_code',
                'timezone',
                'phone',
                'phone_verified_at',
                'trial_ends_at',
                'current_session_id',
                'app_pin_hash',
                'pin_enabled',
                'biometric_enabled',
                'last_login_ip',
                'registration_source',
                'referred_by_user_id',
                'is_suspended',
                'suspended_reason',
            ]);
        });
    }
};
