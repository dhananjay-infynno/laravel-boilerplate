<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per user, created by UserObserver on registration so nothing
 * downstream has to null-check.
 */
class UserSetting extends Model
{
    use BaseModel, HasFactory;

    protected $fillable = [
        'user_id', 'decimal_places', 'theme', 'theme_color', 'language',
        'show_print_option', 'allow_external_transfers', 'require_pin_on_open',
        'pin_timeout_minutes', 'date_format', 'notify_email', 'notify_push',
        'notify_external_transfer', 'notify_payment', 'default_account_id',
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id'];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'show_print_option' => 'boolean',
            'allow_external_transfers' => 'boolean',
            'require_pin_on_open' => 'boolean',
            'pin_timeout_minutes' => 'integer',
            'notify_email' => 'boolean',
            'notify_push' => 'boolean',
            'notify_external_transfer' => 'boolean',
            'notify_payment' => 'boolean',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function defaultAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_account_id');
    }
}
