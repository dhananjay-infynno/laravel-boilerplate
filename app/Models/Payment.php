<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Traits\BaseModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property PaymentStatus $status
 */
class Payment extends Model
{
    use BaseModel, HasFactory, HasUuid;

    protected $fillable = [
        'user_id', 'subscription_id', 'gateway', 'gateway_payment_id',
        'gateway_order_id', 'gateway_invoice_id', 'amount', 'currency_code',
        'status', 'method', 'method_detail', 'failure_code', 'failure_message',
        'refunded_amount', 'paid_at', 'metadata',
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id', 'uuid', 'created_at'];

    protected $exactFilters = ['status', 'gateway', 'method', 'gateway_payment_id'];

    protected $defaultSort = '-created_at';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'status' => PaymentStatus::class,
            'method_detail' => 'array',
            'metadata' => 'array',
            'paid_at' => 'timestamp',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function scopeCaptured(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Captured);
    }
}
