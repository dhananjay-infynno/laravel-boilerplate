<?php

declare(strict_types=1);

namespace App\DataObjects;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;

/**
 * The gateway's view of a subscription, normalised.
 *
 * Used by the reconcile jobs. Gateways drop webhooks and networks fail, so the
 * local state WILL drift — this is what we compare against to repair it, rather
 * than assuming the webhook stream was complete.
 */
final readonly class GatewaySubscription
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $gatewaySubscriptionId,
        public SubscriptionStatus $status,
        public ?CarbonImmutable $currentPeriodStart = null,
        public ?CarbonImmutable $currentPeriodEnd = null,
        public ?CarbonImmutable $endedAt = null,
        public ?string $gatewayPlanId = null,
        public int $paidCount = 0,
        public array $raw = [],
    ) {}
}
