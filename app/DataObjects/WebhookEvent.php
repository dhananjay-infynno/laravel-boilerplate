<?php

declare(strict_types=1);

namespace App\DataObjects;

use App\Enums\WebhookEventType;
use Carbon\CarbonImmutable;

/**
 * A provider webhook, translated into OUR vocabulary.
 *
 * SubscriptionService only ever sees this — never a Razorpay payload. That is
 * what lets a second gateway plug into the same handlers rather than forcing a
 * parallel set.
 */
final readonly class WebhookEvent
{
    /**
     * @param  string  $eventId  The provider's id. UNIQUE — this is the replay defence.
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $eventId,
        public WebhookEventType $type,
        public string $gateway,
        public ?string $gatewaySubscriptionId = null,
        public ?string $gatewayPaymentId = null,
        public ?string $amount = null,
        public ?string $currencyCode = null,
        public ?string $method = null,
        public ?CarbonImmutable $currentPeriodStart = null,
        public ?CarbonImmutable $currentPeriodEnd = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array $raw = [],
    ) {}

    /** Events we receive but have no handler for — recorded, then ignored. */
    public function isIgnorable(): bool
    {
        return $this->type === WebhookEventType::Unknown;
    }
}
