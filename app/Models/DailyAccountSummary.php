<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily rollup per account. A cache, fully derivable from entry_balances.
 *
 * No uuid: this is never addressed by the API, only aggregated. Reports read
 * ONLY from here — never sum entries at request time.
 */
class DailyAccountSummary extends Model
{
    use BaseModel, HasFactory;

    protected $fillable = [
        'user_id', 'account_id', 'summary_date', 'opening_balance', 'closing_balance',
        'total_credit', 'total_debit', 'entry_count', 'credit_count', 'debit_count',
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id', 'created_at'];

    protected $exactFilters = ['account_id', 'summary_date'];

    protected $defaultSort = '-summary_date';

    protected $relationship = [
        'account' => ['model' => Account::class],
    ];

    protected function casts(): array
    {
        return [
            // NOTE: a Carbon, not a string. Anything grouping by date must use
            // ->toDateString() — casting to string yields a full datetime and
            // puts every row in its own group.
            'summary_date' => 'date',
            'opening_balance' => 'decimal:4',
            'closing_balance' => 'decimal:4',
            'total_credit' => 'decimal:4',
            'total_debit' => 'decimal:4',
            'entry_count' => 'integer',
            'credit_count' => 'integer',
            'debit_count' => 'integer',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('summary_date', [$from, $to]);
    }
}
