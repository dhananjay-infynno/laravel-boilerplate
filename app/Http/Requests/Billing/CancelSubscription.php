<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

final class CancelSubscription extends FormRequest
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
             * Defaults to TRUE — cancel at period end.
             *
             * The customer paid through the current period; ending access the
             * instant they tap cancel is taking money for time not given, and
             * it generates chargebacks. Immediate cancellation stays available
             * for support to use deliberately.
             */
            'at_period_end' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'at_period_end' => $this->boolean('at_period_end', true),
        ]);
    }
}
