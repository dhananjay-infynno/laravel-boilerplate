<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErrorCode;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Services\UserSessionService;
use App\Traits\ApiResponser;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Account deletion — required by Google Play, and by GDPR / India's DPDP Act.
 *
 * A 30-day grace rather than an immediate purge:
 *
 *   - financial records should not be destroyed on a mistyped tap
 *   - a user who deletes in anger frequently wants it back the next day
 *   - it gives support a window to recover and us a window to spot abuse
 *
 * The account is suspended immediately, so it behaves as deleted from the
 * user's point of view, and every session is revoked. A scheduled job purges
 * the data after the window.
 *
 * @tags Account
 */
#[Group('Account', weight: 62)]
final class AccountDeletionController extends Controller
{
    use ApiResponser;

    private const GRACE_DAYS = 30;

    public function __construct(
        private readonly UserSessionService $sessions,
    ) {}

    /**
     * Request deletion of the caller's account.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'confirmation' => ['required', 'in:DELETE'],
        ]);

        $user = $request->user();

        if (! Hash::check((string) $validated['current_password'], (string) $user->password)) {
            return $this->error(
                (string) __('validation.custom_messages.current_password'),
                ErrorCode::ValidationFailed,
                422,
            );
        }

        // TODO(phase-5): schedule PurgeDeletedAccountJob at +30 days and mail a
        // cancellation link. Suspending and revoking is the part that MUST
        // happen synchronously; the purge is deliberately deferred.
        $user->forceFill([
            'is_suspended' => true,
            'suspended_reason' => 'deletion_requested',
            'status' => UserStatus::INACTIVE,
        ])->save();

        $this->sessions->endAll($user, 'account_deleted');

        Log::info('Account deletion requested.', [
            'user_id' => $user->id,
            'purge_after_days' => self::GRACE_DAYS,
        ]);

        return $this->success(
            ['grace_days' => self::GRACE_DAYS],
            (string) __('setting.deletion_requested', ['days' => self::GRACE_DAYS]),
        );
    }
}
