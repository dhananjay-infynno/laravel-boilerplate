<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery record for every transactional mail.
 *
 * Bounce and complaint statuses arrive from the provider's webhooks. On this
 * product a lost verification email is a lost signup — the trial does not start
 * until the address is verified — so this is worth having from day one.
 */
class MailLog extends Model
{
    use BaseModel;

    protected $fillable = [
        'user_id', 'to_email', 'mailable', 'subject',
        'status', 'provider_message_id', 'error', 'sent_at',
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id', 'created_at'];

    protected $exactFilters = ['status', 'mailable', 'to_email'];

    protected $defaultSort = '-created_at';

    protected function casts(): array
    {
        return [
            'sent_at' => 'timestamp',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUndelivered(Builder $query): Builder
    {
        return $query->whereIn('status', ['failed', 'bounced', 'complained']);
    }
}
