<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices — `docs/03-BILLING.md` §7.
 *
 * Legal documents. Two rules follow from that:
 *
 *   - `invoice_number` is sequential, gap-free and immutable (allocated by the
 *     same atomic counter pattern as user_sequences, but global)
 *   - `billing_details` is a JSON SNAPSHOT taken at issue time, never a join.
 *     A user editing their address must not silently rewrite last year's tax
 *     records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();

            // INV-2026-000123, resetting per Indian financial year (Apr-Mar).
            $table->string('invoice_number', 40);

            $table->decimal('amount', 12, 2);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->char('currency_code', 3)->default('INR');

            $table->string('status', 20)->default('issued')->comment('See App\\Enums\\InvoiceStatus');

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->string('pdf_path', 255)->nullable();

            // Name, address, GSTIN, state and SAC code as they were at issue.
            $table->json('billing_details')->nullable();

            $table->timestamps();

            $table->unique('uuid', 'uk_invoices_uuid');
            $table->unique('invoice_number', 'uk_invoices_number');
            $table->index(['user_id', 'issued_at'], 'idx_invoices_user');
        });

        // The global gap-free counter behind invoice_number.
        Schema::create('invoice_sequence', function (Blueprint $table) {
            $table->string('financial_year', 9)->primary()->comment('e.g. 2026-2027');
            $table->unsignedBigInteger('next_no')->default(1);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sequence');
        Schema::dropIfExists('invoices');
    }
};
