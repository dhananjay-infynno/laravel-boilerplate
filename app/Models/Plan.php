<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanInterval;
use App\Traits\BaseModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subscription plan. Limits live in `max_accounts` and the feature matrix in
 * `features`, so adding a tier is a row rather than a deploy.
 *
 * @property PlanInterval $interval
 */
class Plan extends Model
{
    use BaseModel, HasFactory, HasUuid;

    protected $fillable = [
        'code', 'name', 'description', 'interval', 'interval_count',
        'max_accounts', 'trial_days', 'features', 'is_active', 'is_visible', 'sort_order',
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id', 'uuid'];

    protected $exactFilters = ['code', 'interval', 'is_active', 'is_visible'];

    protected $defaultSort = 'sort_order';

    protected $relationship = [
        'prices' => ['model' => PlanPrice::class],
    ];

    protected function casts(): array
    {
        return [
            'interval' => PlanInterval::class,
            'interval_count' => 'integer',
            'max_accounts' => 'integer',
            'trial_days' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_visible' => 'boolean',
            'sort_order' => 'integer',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Plans a customer may actually choose — excludes the trial pseudo-plan. */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_visible', true);
    }

    public function feature(string $key, mixed $default = null): mixed
    {
        return data_get($this->features, $key, $default);
    }
}
