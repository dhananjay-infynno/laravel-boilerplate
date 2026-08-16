<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Models\Invoice;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A GST invoice.
 *
 * @property Invoice $resource
 */
#[SchemaName('Invoice')]
final class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => (string) $this->resource->uuid,
            'invoice_number' => (string) $this->resource->invoice_number,
            // Taxable value, tax, and total shown separately because Indian tax
            // law requires the split on the face of the invoice.
            'amount' => (string) $this->resource->amount,
            'tax_amount' => (string) $this->resource->tax_amount,
            'total_amount' => (string) $this->resource->total_amount,
            'currency_code' => (string) $this->resource->currency_code,
            'status' => $this->resource->status->value,
            'issued_at' => $this->resource->issued_at,
            'paid_at' => $this->resource->paid_at,
            // The snapshot taken at issue time, never a live join — a user
            // editing their address must not rewrite last year's tax records.
            'billing_details' => (array) ($this->resource->billing_details ?? []),
            // A signed, short-lived URL rather than the storage path: invoices
            // are private and the path is not a capability.
            'download_url' => $this->resource->pdf_path === null ? null : route(
                'billing.invoices.download',
                ['invoice' => $this->resource->uuid],
            ),
        ];
    }
}
