<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Raw webhook payloads.
 *
 * Written BEFORE processing, so a handler that throws leaves the event
 * recoverable and replayable. `event_id` is unique — that is the replay
 * protection that stops a retried `subscription.charged` extending a period
 * twice.
 *
 * No uuid: never addressed by the public API.
 */
class PaymentEvent extends Model
{
    use BaseModel, HasFactory;

    protected $fillable = [
        'gateway', 'event_id', 'event_type', 'payload', 'signature',
        'status', 'attempts', 'error', 'processed_at',
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id', 'created_at'];

    protected $exactFilters = ['gateway', 'event_type', 'status', 'event_id'];

    protected $defaultSort = '-created_at';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'processed_at' => 'timestamp',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    /** Anything stuck here for more than ~15 minutes should raise an alert. */
    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'failed']);
    }
}
