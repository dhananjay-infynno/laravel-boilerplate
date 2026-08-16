<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->comment('The value written to users.current_session_id');
            $table->unsignedBigInteger('user_id');
            $table->string('token_id', 100)->nullable()->comment('oauth_access_tokens.id');
            $table->string('device_name', 120)->nullable();
            $table->string('device_type', 20)->nullable()->comment('android | ios | web');
            $table->string('app_version', 20)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('location', 120)->nullable()->comment('Coarse geo-IP, city level only');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 50)->nullable()
                ->comment('new_login | logout | admin | password_reset');
            $table->timestamps();

            $table->unique('uuid', 'uk_sessions_uuid');
            $table->index(['user_id', 'revoked_at'], 'idx_sessions_user_active');
            $table->index('token_id', 'idx_sessions_token');

            $table->foreign('user_id', 'fk_user_sessions_user')
                ->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
