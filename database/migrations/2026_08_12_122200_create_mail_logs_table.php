<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mail log — `docs/01-DATABASE-SCHEMA.md` §4.
 *
 * Bounce and complaint statuses come back from the SES/Resend webhooks. A user
 * who says "I never got the OTP" is then answered in one query instead of a
 * guess — and on this product a lost verification email means a lost signup,
 * because the trial does not start until the address is verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('to_email', 255);
            $table->string('mailable', 120);
            $table->string('subject', 255)->nullable();

            $table->string('status', 20)->default('queued')->comment('queued | sent | failed | bounced | complained');
            $table->string('provider_message_id', 255)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'idx_mail_logs_user');
            $table->index('provider_message_id', 'idx_mail_logs_provider_id');
            $table->index(['status', 'created_at'], 'idx_mail_logs_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_logs');
    }
};
