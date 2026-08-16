<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BaseModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan's price in one currency on one gateway.
 *
 * `amount` is in RUPEES. Razorpay wants paise as an integer — convert only in
 * App\Support\Money::toPaise(), never inline.
 */
class PlanPrice extends Model
{
    use BaseModel, HasFactory, HasUuid;

    protected $fillable = [
        'plan_id', 'currency_code', 'amount', 'gateway', 'gateway_plan_id', 'is_active',
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id', 'uuid'];

    protected $exactFilters = ['currency_code', 'gateway', 'is_active'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
