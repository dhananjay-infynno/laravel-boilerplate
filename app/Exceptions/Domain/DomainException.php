<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;
use Exception;

/**
 * Base for every business-rule failure.
 *
 * Services THROW these; controllers never catch. One renderer in
 * bootstrap/app.php turns them into the standard error envelope, which is why
 * there is not a single try/catch in the HTTP layer.
 *
 * `meta()` is the important part: it carries the machine-readable context the
 * client needs to act — the limit that was hit, which accounts are over, how
 * long to wait. An error the client can only display is a dead end.
 */
abstract class DomainException extends Exception
{
    abstract public function errorCode(): ErrorCode;

    /** Defaults to the code's canonical status; override only with reason. */
    public function status(): int
    {
        return $this->errorCode()->status();
    }

    /**
     * Actionable context for the client. Kept free of internal detail — this
     * is returned to whoever triggered it, including someone probing the API.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [];
    }
}
