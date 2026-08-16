<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Models\Payment;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Payment $resource
 */
#[SchemaName('Payment')]
final class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => (string) $this->resource->uuid,
            'amount' => (string) $this->resource->amount,
            'currency_code' => (string) $this->resource->currency_code,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'method' => $this->resource->method,
            'refunded_amount' => (string) ($this->resource->refunded_amount ?? '0.00'),
            /*
             * `failure_message` is the gateway's text, shown to the user so a
             * failure is actionable ("insufficient funds") rather than a shrug.
             * `failure_code` is for support. Neither is a secret.
             */
            'failure_code' => $this->resource->failure_code,
            'failure_message' => $this->resource->failure_message,
            'paid_at' => $this->resource->paid_at,
            'created_at' => $this->resource->created_at,
        ];
    }
}
