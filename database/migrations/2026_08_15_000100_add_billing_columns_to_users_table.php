<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing identity, needed before the first GST invoice can be issued.
 *
 * Kept on `users` rather than a separate table: there is exactly one billing
 * identity per account, and a 1:1 table would buy nothing but a join.
 *
 * Every column is NULLABLE. A consumer buying a Rs 99 plan supplies none of
 * this, and a required GSTIN field at checkout would kill conversion. It only
 * matters for the B2B customers who ask for a proper tax invoice — and note
 * that the values are SNAPSHOT onto the invoice at issue time, so editing them
 * later never rewrites a document already filed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // The legal name to bill, which is often a company rather than the
            // person's own name.
            $table->string('billing_name', 150)->nullable()->after('suspended_reason');
            $table->string('billing_address', 500)->nullable()->after('billing_name');
            $table->string('billing_city', 100)->nullable()->after('billing_address');
            $table->string('billing_postal_code', 12)->nullable()->after('billing_city');

            /*
             * Indian GST state code, e.g. "27" for Maharashtra.
             *
             * This is what decides CGST+SGST versus IGST on the invoice. Get it
             * wrong and the customer cannot claim input credit — they will
             * notice, and it is a correction that requires a credit note.
             */
            $table->char('state_code', 2)->nullable()->after('billing_postal_code');

            // 15 characters, validated at the API boundary, never trusted from
            // the client without a format check.
            $table->string('gstin', 15)->nullable()->after('state_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'billing_name',
                'billing_address',
                'billing_city',
                'billing_postal_code',
                'state_code',
                'gstin',
            ]);
        });
    }
};
