<?php

declare(strict_types=1);

namespace App\Http\Requests\Setting;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateSettings extends FormRequest
{
    private ?int $resolvedDefaultAccountId = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decimal_places' => ['sometimes', 'integer', 'between:0,4'],
            'theme' => ['sometimes', 'in:light,dark,system'],
            'theme_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'language' => ['sometimes', 'string', 'max:5'],
            'show_print_option' => ['sometimes', 'boolean'],
            'allow_external_transfers' => ['sometimes', 'boolean'],
            'require_pin_on_open' => ['sometimes', 'boolean'],
            'pin_timeout_minutes' => ['sometimes', 'integer', 'between:0,1440'],
            'date_format' => ['sometimes', 'string', 'max:20'],
            'notify_email' => ['sometimes', 'boolean'],
            'notify_push' => ['sometimes', 'boolean'],
            'notify_external_transfer' => ['sometimes', 'boolean'],
            'notify_payment' => ['sometimes', 'boolean'],
            // Clients send the uuid; internal ids never cross the API.
            'default_account_uuid' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('default_account_uuid') || $this->input('default_account_uuid') === null) {
                return;
            }

            // Scoped to the caller. Accepting an arbitrary id would be an IDOR
            // that silently points someone's default at another user's account.
            $id = Account::query()
                ->ownedBy((int) $this->user()->id)
                ->where('uuid', $this->input('default_account_uuid'))
                ->value('id');

            if ($id === null) {
                $validator->errors()->add('default_account_uuid', (string) __('validation.exists', ['attribute' => 'default account']));

                return;
            }

            $this->resolvedDefaultAccountId = (int) $id;
        });
    }

    /**
     * The payload to persist, with the uuid swapped for its id.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->safe()->except('default_account_uuid');

        if ($this->has('default_account_uuid')) {
            // An explicit null clears the default; an absent key leaves it be.
            $data['default_account_id'] = $this->input('default_account_uuid') === null
                ? null
                : $this->resolvedDefaultAccountId;
        }

        return $data;
    }
}
