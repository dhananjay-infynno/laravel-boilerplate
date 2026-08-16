<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class Checkout extends FormRequest
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
            /*
             * The plan is chosen by CODE, never by price or amount.
             *
             * Accepting an amount from the client is the classic mistake: the
             * request body is attacker-controlled, and "amount: 1" would be a
             * one-rupee Pro subscription. The server looks the price up.
             */
            'plan_code' => [
                'required', 'string',
                Rule::exists('plans', 'code')
                    ->where('is_active', true)
                    ->where('is_visible', true),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_code.exists' => (string) __('billing.plan_unavailable'),
        ];
    }
}
