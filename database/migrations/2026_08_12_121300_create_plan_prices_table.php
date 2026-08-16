<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One plan, several prices — `docs/03-BILLING.md` §1.
 *
 * Every row today is INR / razorpay. The `currency_code` and `gateway` columns
 * exist anyway: they cost nothing now, and they are what turns "go
 * international" into a seeder rather than a migration on a live billing table.
 *
 * Amounts are stored in RUPEES here. Razorpay wants paise as an integer —
 * convert only in App\Support\Money::toPaise(), never inline. A stray *100 is
 * the classic first-day Razorpay bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');

            // CASCADE: a price is meaningless without its plan. The plan itself
            // is protected from deletion by subscriptions.plan_id RESTRICT.
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();

            $table->char('currency_code', 3)->default('INR');
            $table->decimal('amount', 12, 2);
            $table->string('gateway', 20)->default('razorpay');
            $table->string('gateway_plan_id', 120)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique('uuid', 'uk_plan_prices_uuid');
            $table->unique(['plan_id', 'currency_code', 'gateway'], 'uk_plan_prices_combo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
