<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments — `docs/01-DATABASE-SCHEMA.md` §3.
 *
 * Deduplication key is `gateway_payment_id`: Razorpay retries webhooks, and a
 * duplicated `subscription.charged` must never extend a period twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();

            $table->string('gateway', 20)->default('razorpay');
            $table->string('gateway_payment_id', 120);
            $table->string('gateway_order_id', 120)->nullable();
            $table->string('gateway_invoice_id', 120)->nullable();

            $table->decimal('amount', 12, 2);
            $table->char('currency_code', 3)->default('INR');
            $table->string('status', 24)->comment('See App\\Enums\\PaymentStatus');

            $table->string('method', 20)->nullable()->comment('upi | card | netbanking | wallet');
            $table->json('method_detail')->nullable();

            $table->string('failure_code', 60)->nullable();
            $table->string('failure_message', 255)->nullable();

            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->timestamp('paid_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique('uuid', 'uk_payments_uuid');
            $table->unique('gateway_payment_id', 'uk_payments_gateway_id');
            $table->index(['user_id', 'status'], 'idx_payments_user_status');
            $table->index('paid_at', 'idx_payments_paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
