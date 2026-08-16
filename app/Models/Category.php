<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategoryType;
use App\Traits\BaseModel;
use App\Traits\HasUserActions;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Entry category. `user_id` NULL means a system category shared by everyone.
 *
 * @property CategoryType $type
 */
class Category extends Model
{
    use BaseModel, HasFactory, HasUserActions, HasUuid, SoftDeletes;

    protected $fillable = [
        'user_id', 'name', 'type', 'icon', 'color', 'is_system', 'sort_order',
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id', 'uuid'];

    protected $exactFilters = ['type', 'is_system'];

    protected $defaultSort = 'sort_order';

    protected function casts(): array
    {
        return [
            'type' => CategoryType::class,
            'is_system' => 'boolean',
            'sort_order' => 'integer',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
            'deleted_at' => 'timestamp',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Everything this user may pick: their own plus the system set.
     */
    public function scopeAvailableTo(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId): void {
            $q->whereNull('user_id')->orWhere('user_id', $userId);
        });
    }
}
