<?php

declare(strict_types=1);

namespace App\Http\Requests\ExternalTransfer;

use Illuminate\Foundation\Http\FormRequest;

final class StoreExternalTransfer extends FormRequest
{
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
            'from_account_uuid' => ['required', 'uuid'],
            /*
             * Deliberately NOT `exists:accounts`.
             *
             * A validation error that fires only for account numbers which do
             * NOT exist is an enumeration oracle — an attacker learns which
             * numbers are real from the shape of the 422 alone. The service
             * returns one generic failure for every reason instead.
             */
            'to_account_number' => ['required', 'string', 'min:6', 'max:10'],
            'amount' => ['required', 'decimal:0,4', 'gt:0', 'max_digits:18'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $number = $this->input('to_account_number');

        if (is_string($number)) {
            $this->merge(['to_account_number' => strtoupper(trim($number))]);
        }
    }
}
