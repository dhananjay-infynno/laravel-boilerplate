<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/**
 * The same idempotency key arrived with a DIFFERENT body.
 *
 * This is never a legitimate replay — it is a client bug, or an attempt to
 * smuggle a second payload past deduplication. Replaying the stored response
 * would hide it; 409 surfaces it.
 */
final class DuplicateRequestException extends DomainException
{
    public function __construct()
    {
        parent::__construct((string) __('errors.duplicate_request'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::DuplicateRequest;
    }
}
