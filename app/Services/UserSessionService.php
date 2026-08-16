<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Middleware\EnsureSingleSession;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\PersonalAccessTokenResult;

/**
 * The WRITE side of the single-session contract.
 *
 * Read EnsureSingleSession's docblock before changing anything here — the two
 * must agree exactly, and a mismatch silently disables single-session
 * enforcement rather than failing loudly.
 *
 * The contract:
 *   - `user_sessions.token_id` = the Passport access-token id
 *   - `users.current_session_id` = that row's uuid
 *   - cache key `user:{id}:session` holds BOTH, as an array
 */
final readonly class UserSessionService
{
    /**
     * Start a session for a freshly-issued token and displace every other one.
     *
     * The token must already exist when this is called — start() revokes every
     * OTHER token, so issuing afterwards would revoke the new one too.
     */
    public function start(
        User $user,
        PersonalAccessTokenResult $token,
        ?Request $request = null,
        string $displacedReason = 'new_login',
    ): UserSession {
        $request ??= request();

        /*
         * `accessTokenId` is already in memory from the OAuth server response.
         *
         * `$token->token->id` is the same value but routes through
         * PersonalAccessTokenResult::getToken(), which runs a SELECT — a
         * pointless query on the login hot path.
         */
        $tokenId = (string) $token->accessTokenId;

        return DB::transaction(function () use ($user, $tokenId, $request, $displacedReason): UserSession {
            $this->revokeAllTokens($user, exceptTokenId: $tokenId);
            $this->revokeAllSessions($user, $displacedReason, exceptTokenId: $tokenId);

            $session = UserSession::create([
                'user_id' => $user->id,
                'token_id' => $tokenId,
                'device_name' => $this->header($request, 'X-Device-Name'),
                'device_type' => $this->header($request, 'X-Device-Type'),
                'app_version' => $this->header($request, 'X-App-Version'),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'last_activity_at' => Carbon::now(),
            ]);

            $user->forceFill(['current_session_id' => $session->uuid])->save();

            $this->primeCache((int) $user->id, (string) $session->uuid, $tokenId);

            return $session;
        });
    }

    /** End the session behind the current token. Used by logout. */
    public function end(User $user, ?string $tokenId, string $reason = 'logout'): void
    {
        if ($tokenId !== null) {
            UserSession::query()
                ->ownedBy((int) $user->id)
                ->where('token_id', $tokenId)
                ->active()
                ->update(['revoked_at' => Carbon::now(), 'revoked_reason' => $reason]);
        }

        $user->forceFill(['current_session_id' => null])->save();

        $this->forgetCache((int) $user->id);
    }

    /**
     * Revoke everything. Used by logout-all, password reset, admin
     * force-logout and account deletion.
     */
    public function endAll(User $user, string $reason = 'logout'): void
    {
        DB::transaction(function () use ($user, $reason): void {
            $this->revokeAllTokens($user);
            $this->revokeAllSessions($user, $reason);

            $user->forceFill(['current_session_id' => null])->save();
        });

        $this->forgetCache((int) $user->id);
    }

    /**
     * Revoke one session by uuid.
     *
     * Returns false when it is not theirs — the caller surfaces that as a 404,
     * not a 403, because confirming existence would leak another user's
     * session ids.
     */
    public function revoke(User $user, string $sessionUuid, string $reason = 'logout'): bool
    {
        /** @var UserSession|null $session */
        $session = UserSession::query()
            ->ownedBy((int) $user->id)
            ->where('uuid', $sessionUuid)
            ->first();

        if ($session === null) {
            return false;
        }

        if (is_string($session->token_id) && $session->token_id !== '') {
            $this->revokeTokenById($user, $session->token_id);
        }

        $session->update(['revoked_at' => Carbon::now(), 'revoked_reason' => $reason]);

        if ($user->current_session_id === $session->uuid) {
            $user->forceFill(['current_session_id' => null])->save();
            $this->forgetCache((int) $user->id);
        }

        return true;
    }

    /**
     * Write the EXACT array shape EnsureSingleSession expects.
     *
     * A bare string here is ignored by the middleware, which then silently
     * falls back to a database read on every single request.
     */
    public function primeCache(int $userId, string $sessionUuid, string $tokenId): void
    {
        Cache::put(
            EnsureSingleSession::cacheKey($userId),
            ['session_id' => $sessionUuid, 'token_id' => $tokenId],
            EnsureSingleSession::CACHE_TTL_SECONDS,
        );
    }

    public function forgetCache(int $userId): void
    {
        Cache::forget(EnsureSingleSession::cacheKey($userId));
    }

    private function revokeAllTokens(User $user, ?string $exceptTokenId = null): void
    {
        $query = DB::table('oauth_access_tokens')
            ->where('user_id', $user->id)
            ->where('revoked', false);

        if ($exceptTokenId !== null) {
            $query->where('id', '!=', $exceptTokenId);
        }

        $query->update(['revoked' => true]);
    }

    private function revokeTokenById(User $user, string $tokenId): void
    {
        DB::table('oauth_access_tokens')
            ->where('user_id', $user->id)
            ->where('id', $tokenId)
            ->update(['revoked' => true]);
    }

    private function revokeAllSessions(User $user, string $reason, ?string $exceptTokenId = null): void
    {
        $query = UserSession::query()->ownedBy((int) $user->id)->active();

        if ($exceptTokenId !== null) {
            $query->where('token_id', '!=', $exceptTokenId);
        }

        $query->update(['revoked_at' => Carbon::now(), 'revoked_reason' => $reason]);
    }

    private function header(Request $request, string $name): ?string
    {
        $value = $request->header($name);

        return is_string($value) && $value !== '' ? mb_substr($value, 0, 120) : null;
    }
}
