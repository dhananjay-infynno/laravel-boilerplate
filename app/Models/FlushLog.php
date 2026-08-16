<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable record of a data flush.
 *
 * Never soft-deleted, never purged, never amended — hence no `updated_at`. If a
 * user ever disputes what happened to their data, this row is the answer.
 */
class FlushLog extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'export_id', 'entries_deleted', 'balances_deleted',
        'accounts_affected', 'total_balance_carried', 'snapshot',
        'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'entries_deleted' => 'integer',
            'balances_deleted' => 'integer',
            'accounts_affected' => 'integer',
            'total_balance_carried' => 'decimal:4',
            'snapshot' => 'array',
            'created_at' => 'timestamp',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function export(): BelongsTo
    {
        return $this->belongsTo(Export::class);
    }
}
