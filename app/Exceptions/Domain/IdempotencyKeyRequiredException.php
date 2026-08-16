<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/**
 * A money-writing request arrived without an `Idempotency-Key`.
 *
 * Refused rather than accepted, because the mobile app retries on flaky
 * networks: without a key, a retry posts the user's money twice and there is no
 * way for the server to tell that from a genuine second entry.
 */
final class IdempotencyKeyRequiredException extends DomainException
{
    public function __construct()
    {
        parent::__construct((string) __('errors.idempotency_key_required'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::IdempotencyKeyRequired;
    }
}
