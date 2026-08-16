<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangePlan extends FormRequest
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
            'plan_code' => [
                'required', 'string',
                Rule::exists('plans', 'code')->where('is_active', true)->where('is_visible', true),
            ],
        ];
    }

    /*
     * There is deliberately no `at_cycle_end` input.
     *
     * Whether a change applies now or at the cycle end follows from the
     * DIRECTION of the change, and the server decides: upgrades take effect
     * immediately (the user is paying more and wants the accounts now),
     * downgrades wait for the cycle end (they already paid for the higher
     * tier). Letting the client pick means "downgrade, effective now" — a
     * refund request wearing a costume.
     */
}
