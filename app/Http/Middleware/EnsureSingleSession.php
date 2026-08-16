<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Domain\SessionRevokedException;
use App\Models\User;
use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Passport\TransientToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * One account, one live device — `docs/00-MASTER-PLAN.md` §4.8.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HOW THE SESSION ID TRAVELS WITH THE TOKEN — READ BEFORE WIRING AUTH
 * ─────────────────────────────────────────────────────────────────────────────
 * The master plan says the token "carries the session id as a custom claim".
 * Passport does not support that: the access token is a League OAuth2 JWT whose
 * claims are fixed, and adding one means overriding the token repository and
 * the JWT factory — a lot of surface area to own for a value we can look up.
 *
 * So the session is bound to the token by the token's OWN id instead:
 *
 *   1. On login, UserSessionService creates a `user_sessions` row whose
 *      `token_id` is the Passport access-token id (oauth_access_tokens.id) and
 *      writes that row's `uuid` into `users.current_session_id`.
 *
 *   2. It primes the cache this middleware reads:
 *
 *          Cache::put("user:{$id}:session", [
 *              'session_id' => $session->uuid,
 *              'token_id'   => $tokenId,
 *          ], EnsureSingleSession::CACHE_TTL_SECONDS);
 *
 *      The ARRAY SHAPE is the contract. A bare string is ignored and silently
 *      falls back to a database read on every request.
 *
 *   3. A second login writes a new row and a new token id. The older token
 *      still authenticates — Passport does not know about any of this — but its
 *      id no longer matches, so it is rejected here.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FAILS OPEN, DELIBERATELY
 * ─────────────────────────────────────────────────────────────────────────────
 * When there is no session on record the request is allowed through. A token
 * issued before session tracking existed, or a `Passport::actingAs()` test
 * double, must keep working rather than locking out the entire user base.
 *
 * The cost is that a bug here degrades to "single-session not enforced" rather
 * than "nobody can log in" — quieter, and worth knowing when debugging, which
 * is why AuthFlowTest asserts the session row and token_id exist explicitly.
 *
 * Cache-only in the steady state: this runs on EVERY authenticated request.
 */
final class EnsureSingleSession
{
    /** 24 hours. */
    public const CACHE_TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $tokenId = $this->tokenId($user);

        // No resolvable token id: a session-guard request, a transient token,
        // or a test using actingAs(). Nothing to compare.
        if ($tokenId === null) {
            return $next($request);
        }

        $currentTokenId = $this->currentTokenId($user);

        if ($currentTokenId === null) {
            return $next($request);
        }

        if (! hash_equals($currentTokenId, $tokenId)) {
            throw new SessionRevokedException;
        }

        return $next($request);
    }

    public static function cacheKey(int $userId): string
    {
        return "user:{$userId}:session";
    }

    /**
     * The id of the access token on this request.
     *
     * Passport 13 exposes it on the token model. Deliberately avoids anything
     * that would issue a query — this runs on every request.
     */
    private function tokenId(User $user): ?string
    {
        $token = $user->token();

        if ($token === null || $token instanceof TransientToken) {
            return null;
        }

        $id = $token->getKey();

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    /**
     * The token id of the user's CURRENT session.
     *
     * Cache first; the database only repopulates a cold key.
     */
    private function currentTokenId(User $user): ?string
    {
        $cached = Cache::get(self::cacheKey((int) $user->id));

        if (is_array($cached) && is_string($cached['token_id'] ?? null) && $cached['token_id'] !== '') {
            return $cached['token_id'];
        }

        $sessionUuid = $user->current_session_id;

        if (! is_string($sessionUuid) || $sessionUuid === '') {
            return null;
        }

        /** @var UserSession|null $session */
        $session = UserSession::query()
            ->where('user_id', $user->id)
            ->where('uuid', $sessionUuid)
            ->first();

        // The user's current session was revoked out from under them — this is
        // the one path that genuinely means "signed out elsewhere".
        if ($session === null || $session->revoked_at !== null) {
            throw new SessionRevokedException;
        }

        if (! is_string($session->token_id) || $session->token_id === '') {
            return null;
        }

        Cache::put(
            self::cacheKey((int) $user->id),
            ['session_id' => $sessionUuid, 'token_id' => $session->token_id],
            self::CACHE_TTL_SECONDS,
        );

        return $session->token_id;
    }
}
