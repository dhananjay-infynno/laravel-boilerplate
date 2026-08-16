<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedTinyInteger('decimal_places')->default(2)->comment('0-4, display only');
            $table->string('theme', 20)->default('system')->comment('light | dark | system');
            $table->string('theme_color', 7)->default('#2563EB');
            $table->string('language', 5)->default('en');
            $table->boolean('show_print_option')->default(true);
            $table->boolean('allow_external_transfers')->default(true);
            $table->boolean('require_pin_on_open')->default(false);
            $table->unsignedSmallInteger('pin_timeout_minutes')->default(5);
            $table->string('date_format', 20)->default('d/m/Y');
            $table->boolean('notify_email')->default(true);
            $table->boolean('notify_push')->default(true);
            $table->boolean('notify_external_transfer')->default(true);
            $table->boolean('notify_payment')->default(true);
            // FK deferred to 2026_08_12_122400 — `accounts` does not exist yet.
            $table->unsignedBigInteger('default_account_id')->nullable();
            $table->timestamps();

            $table->unique('user_id', 'uk_user_settings_user');
            $table->index('default_account_id', 'idx_user_settings_default_account');

            $table->foreign('user_id', 'fk_user_settings_user')
                ->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
