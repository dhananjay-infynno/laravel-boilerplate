<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BaseModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Every admin write, and every read of a user's ledger.
 *
 * Append-only — no `updated_at`. An audit trail that can be edited is not one.
 */
class AdminAuditLog extends Model
{
    use BaseModel, HasUuid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_id', 'action', 'auditable_type', 'auditable_id',
        'old_values', 'new_values', 'reason', 'ip_address', 'user_agent',
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id', 'uuid', 'created_at'];

    protected $exactFilters = ['action', 'admin_id', 'auditable_type'];

    protected $defaultSort = '-created_at';

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'timestamp',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
