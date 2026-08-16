<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Enums\AccountStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAccount extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is not in question on a create; the plan limit is enforced
        // in AccountService and the write gate by middleware.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:100',
                // Unique per user among live rows only — a deleted account must
                // not reserve its name forever.
                Rule::unique('accounts')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            // `decimal:0,4`, never `numeric`: numeric accepts 1e5, and nobody
            // typed that as an opening balance.
            'opening_balance' => ['required', 'decimal:0,4', 'min:0', 'max_digits:18'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'is_main' => ['sometimes', 'boolean'],
            'allow_overdraft' => ['sometimes', 'boolean'],
            'status' => ['nullable', Rule::in(array_column(AccountStatus::cases(), 'value'))],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon' => ['nullable', 'string', 'max:40'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'currency_code' => strtoupper((string) ($this->input('currency_code') ?: $this->user()->currency_code ?: 'INR')),
        ]);
    }
}
