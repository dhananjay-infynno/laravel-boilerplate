<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Enums\AccountStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `opening_balance` is deliberately absent.
 *
 * Changing it after entries exist would silently rewrite every downstream
 * balance — the account would no longer reconcile against its own history. The
 * correct fix for a wrong opening balance is an adjustment entry, which leaves
 * an audit trail. AccountService enforces this too.
 */
final class UpdateAccount extends FormRequest
{
    public function authorize(): bool
    {
        // The policy check happens in the controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'name' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('accounts')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('deleted_at')
                    ->ignore($account?->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(array_column(AccountStatus::cases(), 'value'))],
            'allow_overdraft' => ['sometimes', 'boolean'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:40'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }
}
