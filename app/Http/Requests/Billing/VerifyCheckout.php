<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The handshake the client SDK returns when checkout closes.
 *
 * Every field here is ATTACKER-CONTROLLED. The signature proves the client is
 * not inventing a payment, and nothing more — entitlements come from the
 * webhook. See SubscriptionController::verify().
 */
final class VerifyCheckout extends FormRequest
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
            'razorpay_payment_id' => ['required', 'string', 'max:64'],
            'razorpay_subscription_id' => ['required', 'string', 'max:64'],
            'razorpay_signature' => ['required', 'string', 'max:128'],
        ];
    }
}
