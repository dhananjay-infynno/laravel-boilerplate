<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Models\Plan;
use App\Models\PlanPrice;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan as the paywall renders it.
 *
 * @property Plan $resource
 */
#[SchemaName('Plan')]
final class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currency = strtoupper((string) ($request->user()?->currency_code ?: 'INR'));

        /** @var PlanPrice|null $price */
        $price = $this->resource->relationLoaded('prices')
            ? $this->resource->prices
                ->firstWhere(fn (PlanPrice $p): bool => $p->currency_code === $currency && $p->is_active)
            : null;

        return [
            'uuid' => (string) $this->resource->uuid,
            'code' => (string) $this->resource->code,
            'name' => (string) $this->resource->name,
            'description' => $this->resource->description,
            'interval' => $this->resource->interval->value,
            'interval_count' => (int) $this->resource->interval_count,
            'max_accounts' => (int) $this->resource->max_accounts,
            'trial_days' => (int) $this->resource->trial_days,
            'features' => (array) ($this->resource->features ?? []),
            // Money as a STRING. A float here is how Rs 1299 becomes 1298.9999
            // in someone's checkout sheet.
            'amount' => $price?->amount === null ? null : (string) $price->amount,
            'currency_code' => $price?->currency_code ?? $currency,
            'sort_order' => (int) $this->resource->sort_order,
        ];
    }
}
