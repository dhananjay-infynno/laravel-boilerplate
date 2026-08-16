<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Models\Subscription;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Subscription $resource
 */
#[SchemaName('Subscription')]
final class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => (string) $this->resource->uuid,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'plan' => $this->whenLoaded('plan', fn (): array => [
                'code' => (string) $this->resource->plan->code,
                'name' => (string) $this->resource->plan->name,
                'max_accounts' => (int) $this->resource->plan->max_accounts,
                'interval' => $this->resource->plan->interval->value,
            ]),
            /*
             * Gateway ids are NOT exposed. They are useless to the client, and
             * knowing another user's subscription id is a lever an attacker
             * does not need to be handed.
             */
            'current_period_start' => $this->resource->current_period_start,
            'current_period_end' => $this->resource->current_period_end,
            'trial_ends_at' => $this->resource->trial_ends_at,
            'cancel_at_period_end' => (bool) $this->resource->cancel_at_period_end,
            'cancelled_at' => $this->resource->cancelled_at,
            'ends_at' => $this->resource->ends_at,
            // Surfaced so the app can say "update payment before the 21st"
            // instead of locking the user out with no warning.
            'grace_ends_at' => $this->resource->grace_ends_at,
            'failed_payment_count' => (int) $this->resource->failed_payment_count,
            'mandate_method' => $this->resource->mandate_method,
            'mandate_revoked_at' => $this->resource->mandate_revoked_at,
            'can_write' => $this->resource->status->canWrite(),
            'created_at' => $this->resource->created_at,
        ];
    }
}
