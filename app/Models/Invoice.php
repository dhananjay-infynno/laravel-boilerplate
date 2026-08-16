<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Traits\BaseModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A legal document.
 *
 * `billing_details` is a SNAPSHOT taken at issue time, never a join — a user
 * editing their address must not silently rewrite last year's tax records.
 * `invoice_number` is immutable once set.
 *
 * @property InvoiceStatus $status
 */
class Invoice extends Model
{
    use BaseModel, HasFactory, HasUuid;

    protected $fillable = [
        'user_id', 'subscription_id', 'payment_id', 'invoice_number',
        'amount', 'tax_amount', 'total_amount', 'currency_code', 'status',
        'issued_at', 'paid_at', 'pdf_path', 'billing_details',
    ];

    /** BaseModel reads this off OTHER instances, so it must be public. */
    public array $queryable = ['id', 'uuid', 'created_at'];

    protected $exactFilters = ['status', 'invoice_number'];

    protected $defaultSort = '-issued_at';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'status' => InvoiceStatus::class,
            'billing_details' => 'array',
            'issued_at' => 'timestamp',
            'paid_at' => 'timestamp',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
