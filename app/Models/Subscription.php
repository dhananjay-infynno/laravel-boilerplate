<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Traits\BaseModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user has at most one non-terminal subscription at a time — enforced in
 * SubscriptionService and checked by the hourly reconcile job.
 *
 * A `trialing` row has no gateway and no plan_price_id: it is never charged.
 *
 * @property SubscriptionStatus $status
 */
class Subscription extends Model
{
    use BaseModel, HasFactory, HasUuid;

    protected $fillable = [
        'user_id', 'plan_id', 'plan_price_id', 'gateway', 'gateway_subscription_id',
        'gateway_customer_id', 'gateway_mandate_id', 'mandate_method',
        'mandate_max_amount', 'mandate_revoked_at', 'status',
        'current_period_start', 'current_period_end', 'trial_ends_at',
        'cancel_at_period_end', 'cancelled_at', 'ends_at', 'grace_ends_at',
        'failed_payment_count', 'last_payment_at', 'next_billing_at', 'metadata',
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id', 'uuid', 'created_at'];

    protected $exactFilters = [
        'status', 'gateway', 'gateway_subscription_id', 'mandate_method', 'plan_id',
    ];

    protected $defaultSort = '-created_at';

    protected $relationship = [
        'plan' => ['model' => Plan::class],
        'plan_price' => ['model' => PlanPrice::class],
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'mandate_max_amount' => 'decimal:2',
            'mandate_revoked_at' => 'timestamp',
            'current_period_start' => 'timestamp',
            'current_period_end' => 'timestamp',
            'trial_ends_at' => 'timestamp',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'timestamp',
            'ends_at' => 'timestamp',
            'grace_ends_at' => 'timestamp',
            'failed_payment_count' => 'integer',
            'last_payment_at' => 'timestamp',
            'next_billing_at' => 'timestamp',
            'metadata' => 'array',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function planPrice(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Anything the reconcile job still needs to watch.
     *
     * `cancelled` counts: entitlements survive until current_period_end.
     */
    public function scopeNonTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SubscriptionStatus::Trialing,
            SubscriptionStatus::Active,
            SubscriptionStatus::PastDue,
            SubscriptionStatus::Paused,
            SubscriptionStatus::Cancelled,
        ]);
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
