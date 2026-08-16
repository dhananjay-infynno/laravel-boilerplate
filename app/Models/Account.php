<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountStatus;
use App\Traits\BaseModel;
use App\Traits\HasUserActions;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A ledger account — Cash, Bank, Shop, Petty Cash.
 *
 * `current_balance` is the denormalised running total and the read path for
 * every screen. It is only ever written inside EntryService's locked
 * transaction; nothing else may touch it.
 *
 * @property AccountStatus $status
 */
class Account extends Model
{
    use BaseModel, HasFactory, HasUserActions, HasUuid, SoftDeletes;

    protected $fillable = [
        'user_id', 'account_number', 'name', 'description', 'currency_code',
        'opening_balance', 'current_balance', 'is_main', 'allow_overdraft',
        'status', 'is_recalculating', 'sort_order', 'color', 'icon',
        // `main_flag` is a MySQL generated column and must NEVER be here.
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id', 'uuid', 'created_at'];

    protected $exactFilters = ['status', 'is_main', 'currency_code', 'account_number'];

    protected $scopedFilters = ['search'];

    protected $defaultSort = 'sort_order';

    protected $relationship = [
        'user' => ['model' => User::class],
    ];

    protected $appends = ['display_status'];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:4',
            'current_balance' => 'decimal:4',
            'is_main' => 'boolean',
            'allow_overdraft' => 'boolean',
            'is_recalculating' => 'boolean',
            'status' => AccountStatus::class,
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
            'deleted_at' => 'timestamp',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entriesFrom(): HasMany
    {
        return $this->hasMany(Entry::class, 'from_account_id');
    }

    public function entriesTo(): HasMany
    {
        return $this->hasMany(Entry::class, 'to_account_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(EntryBalance::class);
    }

    public function dailySummaries(): HasMany
    {
        return $this->hasMany(DailyAccountSummary::class);
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AccountStatus::Active);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term): void {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('account_number', 'like', "%{$term}%");
        });
    }

    protected function displayStatus(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn (): string => $this->status->label(),
        );
    }
}
