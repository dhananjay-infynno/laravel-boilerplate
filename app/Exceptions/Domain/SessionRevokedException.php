<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ErrorCode;

/**
 * Signed in on another device — one live session per account.
 *
 * The clients treat SESSION_REVOKED specially: clear local state and route to
 * the session-conflict screen, WITHOUT attempting a token refresh. A refresh
 * here would loop.
 *
 * Normal behaviour for a single-session app, so the UI must not present it as a
 * security incident.
 */
final class SessionRevokedException extends DomainException
{
    public function __construct()
    {
        parent::__construct((string) __('errors.session_revoked'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::SessionRevoked;
    }
}
