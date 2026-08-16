<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscriptions — `docs/03-BILLING.md` §5.
 *
 * A user has at most one non-terminal subscription at a time, enforced in
 * SubscriptionService and checked by the hourly reconcile job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();

            // NULLABLE: a trialing subscription has no price row — it is never
            // charged. Same reason `gateway` below is nullable.
            $table->foreignId('plan_price_id')->nullable()->constrained()->restrictOnDelete();

            // NULLABLE: a trial has no gateway at all. A NOT NULL default of
            // 'razorpay' would be a lie that surfaces later in reconciliation.
            $table->string('gateway', 20)->nullable();
            $table->string('gateway_subscription_id', 120)->nullable();
            $table->string('gateway_customer_id', 120)->nullable();
            $table->string('gateway_mandate_id', 120)->nullable();

            $table->string('mandate_method', 20)->nullable()->comment('upi_autopay | card | netbanking');
            // The ceiling the user authorised — recommended Rs 2,000, not the
            // plan price, so an upgrade never needs re-authorisation.
            $table->decimal('mandate_max_amount', 12, 2)->nullable();
            // Set by the mandate.revoked webhook: with UPI Autopay a user can
            // cancel from their UPI app without ever opening this product.
            $table->timestamp('mandate_revoked_at')->nullable();

            $table->string('status', 20)->default('trialing')->comment('See App\\Enums\\SubscriptionStatus');

            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();

            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();

            $table->unsignedTinyInteger('failed_payment_count')->default(0);
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamp('next_billing_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique('uuid', 'uk_subs_uuid');
            $table->index(['user_id', 'status'], 'idx_subs_user_status');
            $table->index(['status', 'current_period_end'], 'idx_subs_period_end');

            // NULLs are distinct in MySQL, so rows created before the gateway
            // responds (and trials, which never get one) do not collide.
            $table->unique(['gateway', 'gateway_subscription_id'], 'uk_subs_gateway');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
