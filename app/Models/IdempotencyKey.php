<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable fallback behind the idempotency cache, and the audit record.
 *
 * `key` is a MySQL reserved word — Blueprint and Eloquent quote it, but any raw
 * SQL touching this column must backtick it.
 */
class IdempotencyKey extends Model
{
    protected $fillable = [
        'user_id', 'key', 'endpoint', 'request_hash',
        'response_code', 'response_body', 'status', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_code' => 'integer',
            'response_body' => 'array',
            'expires_at' => 'timestamp',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
