<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EntryDirection;
use App\Enums\EntryStatus;
use App\Enums\EntryType;
use App\Traits\BaseModel;
use App\Traits\HasUserActions;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A ledger entry. The largest table in the system.
 *
 * `amount` is always POSITIVE — the sign lives in `direction`. Nothing here
 * mutates a balance; that is EntryService's job, under lock.
 *
 * @property EntryType $type
 * @property EntryStatus $status
 * @property EntryDirection $direction
 */
class Entry extends Model
{
    use BaseModel, HasFactory, HasUserActions, HasUuid, SoftDeletes;

    protected $fillable = [
        'user_id', 'sr_no', 'entry_date', 'entry_time', 'type', 'direction',
        'from_account_id', 'to_account_id', 'amount', 'currency_code', 'status',
        'remarks', 'reference_no', 'category_id', 'party_id',
        'counterparty_user_id', 'counterparty_account_id', 'linked_entry_id',
        'parent_entry_id', 'expires_at', 'responded_at', 'idempotency_key',
        'attachment_count',
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id', 'uuid', 'created_at'];

    protected $exactFilters = [
        'type', 'status', 'direction', 'from_account_id', 'to_account_id',
        'category_id', 'party_id', 'entry_date', 'sr_no',
    ];

    protected $scopedFilters = ['search'];

    protected $defaultSort = '-entry_date';

    protected $relationship = [
        'from_account' => ['model' => Account::class],
        'to_account' => ['model' => Account::class],
        'category' => ['model' => Category::class],
        'party' => ['model' => Party::class],
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'entry_time' => 'string',
            'amount' => 'decimal:4',
            'type' => EntryType::class,
            'status' => EntryStatus::class,
            'direction' => EntryDirection::class,
            'expires_at' => 'timestamp',
            'responded_at' => 'timestamp',
            'attachment_count' => 'integer',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
            'deleted_at' => 'timestamp',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function counterpartyUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counterparty_user_id');
    }

    public function counterpartyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counterparty_account_id');
    }

    /** The mirror entry in the counterparty's book, once accepted. */
    public function linkedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'linked_entry_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(EntryBalance::class);
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /** Entries that actually moved money — excludes PENDING and every terminal-but-unpaid state. */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereIn('status', [EntryStatus::Completed, EntryStatus::Accepted]);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term): void {
            $q->where('remarks', 'like', "%{$term}%")
                ->orWhere('reference_no', 'like', "%{$term}%");
        });
    }
}
