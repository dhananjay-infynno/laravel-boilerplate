<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Public identifier support for models that carry a `uuid` column alongside
 * the internal auto-increment primary key (option B of 01-DATABASE-SCHEMA.md §0).
 *
 * The internal BIGINT id is used for joins and foreign keys; the uuid is the
 * only identifier that ever leaves the API. UUIDv7 is used deliberately — it is
 * time-ordered, which keeps B-tree inserts sequential on the large tables.
 */
trait HasUuid
{
    protected static function bootHasUuid(): void
    {
        static::creating(function (Model $model): void {
            $column = $model->getUuidColumn();

            if (blank($model->getAttribute($column))) {
                $model->setAttribute($column, (string) Str::uuid7());
            }
        });
    }

    /** The column holding the public identifier. */
    public function getUuidColumn(): string
    {
        return 'uuid';
    }

    /**
     * Route-model binding resolves on the uuid, never on the internal id.
     *
     * NOTE: User overrides this back to `id` — its admin routes are bound by id.
     */
    public function getRouteKeyName(): string
    {
        return $this->getUuidColumn();
    }

    /**
     * @param  string|array<int, string>  $uuid
     */
    public function scopeWhereUuid(Builder $query, string|array $uuid): Builder
    {
        $column = $this->getUuidColumn();

        return is_array($uuid)
            ? $query->whereIn($column, $uuid)
            : $query->where($column, $uuid);
    }
}
