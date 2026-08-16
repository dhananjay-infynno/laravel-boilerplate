<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/**
 * Trial over or subscription lapsed — the clients open the paywall on any 402.
 *
 * Only ever thrown from WRITE paths. Reads and exports stay open: locking
 * someone out of their own financial records is bad practice and a legal
 * problem under GDPR and India's DPDP Act.
 */
final class SubscriptionRequiredException extends DomainException
{
    public function __construct(private readonly string $status = '')
    {
        parent::__construct((string) __('errors.subscription_required'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::SubscriptionRequired;
    }

    public function meta(): array
    {
        return $this->status !== '' ? ['status' => $this->status] : [];
    }
}
