<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Models\User;
use App\Services\Razorpay\RazorpayGateway;
use RuntimeException;

/**
 * Resolves the gateway for a user.
 *
 * Returns Razorpay unconditionally today — the product is India only. When
 * international arrives this becomes a country check and NOTHING else in the
 * codebase changes, which is the entire point of the interface.
 *
 * Note that a user's country is frozen once a subscription is active: a
 * customer must not be able to flip it mid-subscription to change what they
 * are charged.
 */
final class PaymentGatewayManager
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function __construct(RazorpayGateway $razorpay)
    {
        $this->gateways[$razorpay->code()] = $razorpay;
    }

    public function for(User $user): PaymentGateway
    {
        // Every user routes to Razorpay while the product is India only.
        // The signature takes a User so the call sites do not change when
        // country-based routing lands.
        return $this->driver('razorpay');
    }

    public function driver(string $code): PaymentGateway
    {
        return $this->gateways[$code]
            ?? throw new RuntimeException("No payment gateway registered for '{$code}'.");
    }

    public function default(): PaymentGateway
    {
        return $this->driver('razorpay');
    }
}
