<?php

declare(strict_types=1);

namespace App\Http\Requests\Setting;

use App\Services\PinService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class SetPin extends FormRequest
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
            'pin' => ['required', 'string', 'regex:/^\d{4}$|^\d{6}$/'],
            // Requires the account password: otherwise anyone holding an
            // unlocked phone could silently change the lock, which defeats it.
            'current_password' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $pin = (string) $this->input('pin');

            if ($pin !== '' && PinService::isForbidden($pin)) {
                $validator->errors()->add('pin', (string) __('validation.custom_messages.weak_pin'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin.regex' => (string) __('validation.custom_messages.pin_format'),
        ];
    }
}
