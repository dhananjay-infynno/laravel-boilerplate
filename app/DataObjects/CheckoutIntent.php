<?php

declare(strict_types=1);

namespace App\DataObjects;

/**
 * Everything the client needs to open the gateway's checkout.
 *
 * Deliberately contains NO secret: `keyId` is the publishable key, safe to ship
 * to a phone. The key secret never leaves the server.
 */
final readonly class CheckoutIntent
{
    /**
     * @param  array<string, mixed>  $prefill  Name/email/contact, to save typing
     * @param  array<string, mixed>  $notes    Round-tripped back on the webhook
     */
    public function __construct(
        public string $gateway,
        public string $gatewaySubscriptionId,
        public string $keyId,
        public string $planCode,
        public string $amount,
        public string $currencyCode,
        public array $prefill = [],
        public array $notes = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'gateway' => $this->gateway,
            'razorpay_subscription_id' => $this->gatewaySubscriptionId,
            'key_id' => $this->keyId,
            'plan_code' => $this->planCode,
            'amount' => $this->amount,
            'currency_code' => $this->currencyCode,
            'prefill' => $this->prefill,
            'notes' => $this->notes,
        ];
    }
}
