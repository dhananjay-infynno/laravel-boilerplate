<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BaseModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log of logins. This row's `uuid` is the value stored in
 * `users.current_session_id` and compared by EnsureSingleSession.
 *
 * `token_id` holds the Passport access-token id (oauth_access_tokens.id) and
 * must never be exposed in an API response — it identifies a live credential.
 */
class UserSession extends Model
{
    use BaseModel, HasFactory, HasUuid;

    protected $fillable = [
        'user_id', 'token_id', 'device_name', 'device_type', 'app_version',
        'ip_address', 'user_agent', 'location', 'last_activity_at',
        'revoked_at', 'revoked_reason',
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id', 'uuid', 'created_at'];

    protected $exactFilters = ['device_type', 'revoked_reason', 'token_id'];

    protected $defaultSort = '-created_at';

    protected $hidden = ['token_id'];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'timestamp',
            'revoked_at' => 'timestamp',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function scopeRevoked(Builder $query): Builder
    {
        return $query->whereNotNull('revoked_at');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
