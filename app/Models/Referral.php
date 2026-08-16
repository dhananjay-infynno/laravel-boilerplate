<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReferralStatus;
use App\Traits\BaseModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A referral.
 *
 * "Qualified" must mean the referred user PAID, not merely signed up —
 * otherwise trial farming simply becomes rewarded trial farming.
 *
 * @property ReferralStatus $status
 */
class Referral extends Model
{
    use BaseModel, HasFactory, HasUuid;

    protected $fillable = [
        'referrer_user_id', 'referred_user_id', 'code',
        'status', 'reward_days', 'qualified_at', 'rewarded_at',
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id', 'uuid', 'created_at'];

    protected $exactFilters = ['status', 'code'];

    protected function casts(): array
    {
        return [
            'status' => ReferralStatus::class,
            'reward_days' => 'integer',
            'qualified_at' => 'timestamp',
            'rewarded_at' => 'timestamp',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }
}
