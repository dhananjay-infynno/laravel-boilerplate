<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExportStatus;
use App\Traits\BaseModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated data export.
 *
 * `downloaded_at` is the gate on flushing: a user must have the file in hand
 * before their ledger is wiped.
 *
 * @property ExportStatus $status
 */
class Export extends Model
{
    use BaseModel, HasFactory, HasUuid;

    protected $fillable = [
        'user_id', 'type', 'format', 'filters', 'status', 'file_path',
        'file_size', 'file_hash', 'row_count', 'expires_at', 'downloaded_at',
        'download_count', 'error', 'flush_requested', 'flushed_at',
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id', 'uuid', 'created_at'];

    protected $exactFilters = ['status', 'type', 'format'];

    protected $defaultSort = '-created_at';

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'status' => ExportStatus::class,
            'file_size' => 'integer',
            'row_count' => 'integer',
            'download_count' => 'integer',
            'flush_requested' => 'boolean',
            'expires_at' => 'timestamp',
            'downloaded_at' => 'timestamp',
            'flushed_at' => 'timestamp',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Only a completed, confirmed-downloaded export may unlock a flush. */
    public function scopeFlushable(Builder $query): Builder
    {
        return $query->where('status', ExportStatus::Completed)->whereNotNull('downloaded_at');
    }
}
