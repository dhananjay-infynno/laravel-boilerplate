<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PartyType;
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
 * A customer or supplier. `current_balance` positive means they owe the user.
 *
 * @property PartyType $type
 */
class Party extends Model
{
    use BaseModel, HasFactory, HasUserActions, HasUuid, SoftDeletes;

    protected $fillable = [
        'user_id', 'name', 'phone', 'email', 'type',
        'opening_balance', 'current_balance', 'notes',
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id', 'uuid', 'created_at'];

    protected $exactFilters = ['type', 'phone'];

    protected $scopedFilters = ['search'];

    protected $defaultSort = 'name';

    protected function casts(): array
    {
        return [
            'type' => PartyType::class,
            'opening_balance' => 'decimal:4',
            'current_balance' => 'decimal:4',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
            'deleted_at' => 'timestamp',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term): void {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%");
        });
    }
}
