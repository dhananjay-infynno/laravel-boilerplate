<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw webhook log — `docs/03-BILLING.md` §4.
 *
 * Every webhook is written here FIRST, then processed from the queue. If
 * processing throws, the row survives and can be replayed from the admin panel.
 *
 * `event_id` UNIQUE is the replay protection: gateways retry, and a duplicated
 * `subscription.charged` must be a no-op rather than a second period extension.
 *
 * This table is the difference between "a payment went missing" being a
 * five-minute fix and a three-day incident. Never prune it aggressively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 20)->default('razorpay');
            $table->string('event_id', 120);
            $table->string('event_type', 80);

            $table->json('payload');
            $table->string('signature', 255)->nullable();

            $table->string('status', 20)->default('pending')->comment('pending | processed | failed | ignored');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->unique('event_id', 'uk_payment_events_event_id');
            $table->index(['status', 'created_at'], 'idx_payment_events_status');
            $table->index('event_type', 'idx_payment_events_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
