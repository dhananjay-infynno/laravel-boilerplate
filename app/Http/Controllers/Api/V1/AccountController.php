<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\StoreAccount;
use App\Http\Requests\Account\UpdateAccount;
use App\Http\Resources\Account\Resource as AccountResource;
use App\Models\Account;
use App\Services\AccountService;
use App\Traits\ApiResponser;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @tags Accounts
 */
#[Group('Accounts', weight: 20)]
final class AccountController extends Controller
{
    use ApiResponser, AuthorizesRequests;

    public function __construct(
        private readonly AccountService $accountService,
    ) {}

    /**
     * List accounts.
     *
     * Totals ride in `meta` — clients must read them from there rather than
     * summing `current_balance` across the page, which is a client-derived
     * balance and wrong the moment a second device is involved.
     */
    public function index(): JsonResponse
    {
        $userId = (int) Auth::id();

        return $this->collection(
            AccountResource::collection($this->accountService->paginate($userId)),
            null,
            $this->accountService->totals($userId),
        );
    }

    /**
     * Create an account.
     */
    public function store(StoreAccount $request): JsonResponse
    {
        $account = $this->accountService->create((int) Auth::id(), $request->validated());

        return $this->resource(new AccountResource($account), (string) __('account.created'), 201);
    }

    /**
     * Show an account.
     */
    public function show(Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        return $this->resource(new AccountResource($account->loadCount('entriesFrom')));
    }

    /**
     * Update an account.
     */
    public function update(UpdateAccount $request, Account $account): JsonResponse
    {
        $this->authorize('update', $account);

        $account = $this->accountService->update($account, $request->validated());

        return $this->resource(new AccountResource($account), (string) __('account.updated'));
    }

    /**
     * Delete an account.
     */
    public function destroy(Account $account): JsonResponse
    {
        $this->authorize('delete', $account);

        $this->accountService->delete($account);

        return $this->success(null, (string) __('account.deleted'));
    }

    /**
     * Make this the main account, demoting the current one atomically.
     */
    public function setMain(Account $account): JsonResponse
    {
        $this->authorize('setMain', $account);

        return $this->resource(
            new AccountResource($this->accountService->setMain($account)),
            (string) __('account.main_set'),
        );
    }

    /**
     * Apply a manual ordering.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'accounts' => ['required', 'array', 'min:1', 'max:100'],
            'accounts.*.uuid' => ['required', 'uuid'],
            'accounts.*.sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        $this->accountService->reorder((int) Auth::id(), $validated['accounts']);

        return $this->success(null, (string) __('account.reordered'));
    }

    /**
     * Look up an account by number, for an external transfer.
     *
     * Rate limited to 10/min at the route. Returns only a masked holder name,
     * and answers IDENTICALLY for an unknown number, an inactive account and
     * one whose owner has transfers switched off — anything else lets someone
     * map which numbers belong to real users.
     */
    public function lookup(string $accountNumber): JsonResponse
    {
        $result = $this->accountService->lookup($accountNumber);

        return $this->success($result ?? [
            'account_number' => strtoupper(trim($accountNumber)),
            'holder_name_masked' => null,
            'accepts_transfers' => false,
        ]);
    }
}
