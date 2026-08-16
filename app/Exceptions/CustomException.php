<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\Exceptions\Domain\DomainException;

/**
 * The boilerplate's original catch-all exception, kept so the legacy services
 * (AuthService, LanguageService) keep working unchanged.
 *
 * Now extends DomainException so it renders through the same envelope as
 * everything else — otherwise the old services would emit a completely
 * different error shape and every client would need two code paths.
 *
 * DEPRECATED for new code. A specific exception in App\Exceptions\Domain
 * carries a real error_code the client can switch on; this one can only say
 * "something was wrong", which is a dead end for the UI.
 */
class CustomException extends DomainException
{
    public function __construct(
        public string $messageStr,
        public int $resCode = 400,
    ) {
        parent::__construct($messageStr);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ValidationFailed;
    }

    /** Honours the status the legacy call sites passed in. */
    public function status(): int
    {
        return $this->resCode;
    }

    public function report(): bool
    {
        // Expected business failure, not a defect — nothing to log.
        return false;
    }
}
