<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_devices', function (Blueprint $table): void {
            // Hashed, never the raw fingerprint. Feeds the trial-abuse report (master plan §R5).
            $table->string('device_fingerprint', 64)->nullable()->after('device_type');

            $table->index('device_fingerprint', 'idx_user_devices_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table): void {
            $table->dropIndex('idx_user_devices_fingerprint');
            $table->dropColumn('device_fingerprint');
        });
    }
};
