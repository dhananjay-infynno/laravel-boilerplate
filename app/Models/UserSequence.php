<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user gap-free counters for entry and balance serial numbers.
 *
 * Allocation NEVER happens through this model — UserSequenceService uses an
 * atomic `UPDATE ... SET n = LAST_INSERT_ID(n) + 1` because a read-modify-write
 * through Eloquent would hand two concurrent entries the same sr_no.
 *
 * The model exists for relations and seeding only.
 */
class UserSequence extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    public const CREATED_AT = null;

    protected $fillable = ['user_id', 'entry_next_no', 'balance_next_no'];

    protected function casts(): array
    {
        return [
            'entry_next_no' => 'integer',
            'balance_next_no' => 'integer',
            'updated_at' => 'timestamp',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
