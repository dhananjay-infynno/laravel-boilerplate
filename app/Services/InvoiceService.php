<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * GST invoices.
 *
 * These are LEGAL DOCUMENTS, not receipts, and the rules are not negotiable:
 *
 *  1. Numbers are SEQUENTIAL and GAP-FREE within a financial year. A gap is a
 *     question from an auditor you cannot answer.
 *  2. A number, once issued, is IMMUTABLE. Corrections are a credit note, never
 *     an edit.
 *  3. Billing details are SNAPSHOT at issue time. A user editing their address
 *     must not silently rewrite last year's tax record.
 *
 * The Indian financial year runs 1 April to 31 March, and the series RESETS on
 * 1 April. Numbering by calendar year is a common and expensive mistake.
 *
 * Have a CA review the first invoice this produces. The format below is
 * conventional, not authoritative.
 */
final readonly class InvoiceService
{
    /**
     * Issue an invoice for a captured payment.
     *
     * Idempotent on payment_id: a replayed `payment.captured` must not produce a
     * second tax document for the same money.
     */
    public function issueFor(Payment $payment): Invoice
    {
        $existing = Invoice::query()->where('payment_id', $payment->id)->first();

        if ($existing instanceof Invoice) {
            return $existing;
        }

        /** @var User $user */
        $user = $payment->user()->firstOrFail();

        $total = Money::normalise((string) $payment->amount);
        [$taxable, $tax] = $this->split($total);

        $issuedAt = CarbonImmutable::now();

        return DB::transaction(function () use ($payment, $user, $total, $taxable, $tax, $issuedAt): Invoice {
            $number = $this->nextNumber($issuedAt);

            return Invoice::create([
                'user_id' => $user->id,
                'subscription_id' => $payment->subscription_id,
                'payment_id' => $payment->id,
                'invoice_number' => $number,
                'amount' => $taxable,
                'tax_amount' => $tax,
                'total_amount' => $total,
                'currency_code' => (string) $payment->currency_code,
                'status' => InvoiceStatus::Paid,
                'issued_at' => $issuedAt,
                'paid_at' => $payment->paid_at ?? $issuedAt,
                'billing_details' => $this->snapshot($user, $tax),
            ]);
        });
    }

    /**
     * Split an INCLUSIVE total into taxable value and tax.
     *
     * Prices are GST-inclusive (config/razorpay.php) — Rs 99 means Rs 99 leaves
     * the customer's account. So the taxable value is BACKED OUT:
     *
     *     taxable = total / (1 + rate/100)
     *     tax     = total - taxable
     *
     * Tax is computed by SUBTRACTION, never as `taxable * rate`. Rounding both
     * halves independently produces sums that miss the total by a paisa, and an
     * invoice whose parts do not add up is a rejected filing.
     *
     * @return array{0: string, 1: string} [taxable, tax]
     */
    public function split(string $total): array
    {
        if (! (bool) config('razorpay.gst.enabled', true)) {
            return [$total, Money::ZERO];
        }

        $rate = (string) config('razorpay.gst.rate', 18.0);

        if (! (bool) config('razorpay.gst.inclusive', true)) {
            $tax = Money::div(Money::mul($total, $rate), '100');

            return [$total, $tax];
        }

        $divisor = Money::add('1', Money::div($rate, '100'));
        $taxable = Money::div($total, $divisor);
        $tax = Money::sub($total, $taxable);

        return [$taxable, $tax];
    }

    /**
     * Allocate the next number in the current financial year.
     *
     * Same atomic-counter technique as UserSequenceService: `UPDATE ... SET
     * next_no = LAST_INSERT_ID(next_no) + 1` rather than `MAX + 1`. Two
     * simultaneous payments would otherwise both read the same number and one
     * invoice would be lost to a unique-key violation.
     *
     * MUST be called inside a transaction with the insert.
     */
    private function nextNumber(CarbonImmutable $at): string
    {
        $fy = $this->financialYear($at);

        DB::table('invoice_sequence')->insertOrIgnore([
            'financial_year' => $fy,
            'next_no' => 1,
            'updated_at' => now(),
        ]);

        DB::update(
            'UPDATE `invoice_sequence`
             SET `next_no` = LAST_INSERT_ID(`next_no`) + 1, `updated_at` = ?
             WHERE `financial_year` = ?',
            [now(), $fy],
        );

        $serial = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS id')->id;

        $prefix = (string) config('app.invoice_prefix', 'FT');
        $short = substr($fy, 2, 2).substr($fy, -2); // 2026-2027 -> 2627

        return sprintf('%s/%s/%06d', $prefix, $short, $serial);
    }

    /**
     * Indian FY: 1 April to 31 March. April 2026 and January 2027 are both
     * "2026-2027".
     */
    public function financialYear(CarbonImmutable $at): string
    {
        $start = $at->month >= 4 ? $at->year : $at->year - 1;

        return sprintf('%d-%d', $start, $start + 1);
    }

    /**
     * Frozen copy of who was billed and how the tax was composed.
     *
     * CGST+SGST when the customer is in the same state as the supplier, IGST
     * otherwise. Getting this wrong means the customer cannot claim input
     * credit, and they will notice.
     *
     * @return array<string, mixed>
     */
    private function snapshot(User $user, string $tax): array
    {
        $supplierState = (string) config('razorpay.gst.state_code', '');
        $customerState = (string) ($user->state_code ?? '');
        $intraState = $supplierState !== '' && $supplierState === $customerState;

        $half = Money::div($tax, '2');

        return [
            // billing_name first: a B2B customer is billed as the company, not
            // as the person who happens to hold the login.
            'customer_name' => (string) ($user->billing_name ?: $user->name),
            'customer_email' => (string) $user->email,
            'customer_address' => $user->billing_address,
            'customer_city' => $user->billing_city,
            'customer_postal_code' => $user->billing_postal_code,
            'customer_gstin' => $user->gstin,
            'customer_state_code' => $customerState ?: null,
            'supplier_gstin' => config('razorpay.gst.gstin'),
            'supplier_state_code' => $supplierState ?: null,
            'sac_code' => config('razorpay.gst.sac_code'),
            'gst_rate' => config('razorpay.gst.rate'),
            'place_of_supply' => $customerState ?: $supplierState,
            'tax_breakup' => $intraState
                ? ['cgst' => $half, 'sgst' => $half, 'igst' => Money::ZERO]
                : ['cgst' => Money::ZERO, 'sgst' => Money::ZERO, 'igst' => $tax],
        ];
    }
}
