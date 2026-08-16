<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\SetPin;
use App\Http\Requests\Setting\UpdateSettings;
use App\Models\UserSetting;
use App\Services\PinService;
use App\Traits\ApiResponser;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Settings
 */
#[Group('Settings', weight: 60)]
final class SettingController extends Controller
{
    use ApiResponser;

    public function __construct(
        private readonly PinService $pins,
    ) {}

    /**
     * Get the caller's settings.
     *
     * The row is guaranteed to exist — UserObserver creates it on registration
     * — so nothing downstream has to null-check.
     */
    public function show(Request $request): JsonResponse
    {
        return $this->success($this->present($request->user()->settings));
    }

    /**
     * Update settings.
     */
    public function update(UpdateSettings $request): JsonResponse
    {
        $settings = $request->user()->settings;
        $settings->update($request->payload());

        return $this->success($this->present($settings->refresh()), (string) __('setting.updated'));
    }

    /**
     * Set or change the in-app lock code.
     */
    public function setPin(SetPin $request): JsonResponse
    {
        $this->pins->set(
            $request->user(),
            (string) $request->validated('pin'),
            (string) $request->validated('current_password'),
        );

        return $this->success(null, (string) __('setting.pin_set'));
    }

    /**
     * Verify the lock code.
     *
     * Throttled hard at the route — that limit, not the code length, is what
     * makes a 4-digit PIN acceptable at all.
     */
    public function verifyPin(Request $request): JsonResponse
    {
        $validated = $request->validate(['pin' => ['required', 'string']]);

        return $this->success([
            'valid' => $this->pins->verify($request->user(), (string) $validated['pin']),
        ]);
    }

    /**
     * Remove the lock code.
     */
    public function removePin(Request $request): JsonResponse
    {
        $validated = $request->validate(['current_password' => ['required', 'string']]);

        $this->pins->remove($request->user(), (string) $validated['current_password']);

        return $this->success(null, (string) __('setting.pin_removed'));
    }

    /**
     * `default_account_id` is swapped for the account's uuid — internal ids
     * never leave the API.
     *
     * @return array<string, mixed>
     */
    private function present(UserSetting $settings): array
    {
        $data = $settings->toArray();

        unset($data['id'], $data['user_id'], $data['default_account_id']);

        $data['default_account_uuid'] = $settings->default_account_id !== null
            ? $settings->defaultAccount?->uuid
            : null;

        return $data;
    }
}
