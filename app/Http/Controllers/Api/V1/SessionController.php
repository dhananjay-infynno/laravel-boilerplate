<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserSession;
use App\Services\UserSessionService;
use App\Traits\ApiResponser;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Sessions
 */
#[Group('Sessions', weight: 61)]
final class SessionController extends Controller
{
    use ApiResponser;

    public function __construct(
        private readonly UserSessionService $sessions,
    ) {}

    /**
     * List the caller's sessions.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $sessions = UserSession::query()
            ->ownedBy((int) $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (UserSession $s): array => [
                'uuid' => (string) $s->uuid,
                'device_name' => $s->device_name,
                'device_type' => $s->device_type,
                'app_version' => $s->app_version,
                'ip_address' => $s->ip_address,
                'location' => $s->location,
                'last_activity_at' => $s->last_activity_at,
                'created_at' => $s->created_at,
                'revoked_at' => $s->revoked_at,
                'revoked_reason' => $s->revoked_reason,
                'is_current' => $s->uuid === $user->current_session_id,
                // token_id is deliberately absent — it identifies a live
                // credential and has no business in a response body. The model
                // also hides it, but being explicit here matters.
            ])
            ->all();

        return $this->success($sessions);
    }

    /**
     * Revoke a session. Revoking the current one is simply a logout.
     */
    public function destroy(Request $request, string $session): JsonResponse
    {
        $revoked = $this->sessions->revoke($request->user(), $session);

        // 404, not 403: a 403 would confirm that session uuid exists.
        abort_unless($revoked, 404);

        return $this->success(null, (string) __('setting.session_revoked'));
    }
}
