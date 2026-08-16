<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EntryDirection;
use App\Traits\BaseModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The immutable audit trail: opening and closing balance per (entry x account).
 *
 * APPEND-ONLY in normal operation. A deleted entry flags its rows
 * `is_reversed`; it never removes them. The only UPDATE that should ever touch
 * this table is RecalculateAccountBalancesJob replaying a chain — and because
 * of the CHECK constraint it must rewrite opening and closing together.
 *
 * @property EntryDirection $direction
 */
class EntryBalance extends Model
{
    use BaseModel, HasFactory, HasUuid;

    protected $fillable = [
        'sr_no', 'user_id', 'account_id', 'entry_id', 'entry_date',
        'direction', 'amount', 'opening_balance', 'closing_balance', 'is_reversed',
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id', 'uuid', 'created_at'];

    protected $exactFilters = ['account_id', 'entry_id', 'direction', 'is_reversed'];

    protected $defaultSort = 'entry_date';

    protected $relationship = [
        'account' => ['model' => Account::class],
        'entry' => ['model' => Entry::class],
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'amount' => 'decimal:4',
            'opening_balance' => 'decimal:4',
            'closing_balance' => 'decimal:4',
            'direction' => EntryDirection::class,
            'is_reversed' => 'boolean',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('entry_date', [$from, $to]);
    }

    /** Rows that still count toward the running balance. */
    public function scopeNotReversed(Builder $query): Builder
    {
        return $query->where('is_reversed', false);
    }
}
