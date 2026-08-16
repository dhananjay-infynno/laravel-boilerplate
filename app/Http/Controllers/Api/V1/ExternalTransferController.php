<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExternalTransfer\StoreExternalTransfer;
use App\Http\Resources\Entry\Resource as EntryResource;
use App\Models\Entry;
use App\Services\ExternalTransferService;
use App\Traits\ApiResponser;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @tags External Transfers
 */
#[Group('External Transfers', weight: 40)]
final class ExternalTransferController extends Controller
{
    use ApiResponser;

    public function __construct(
        private readonly ExternalTransferService $transfers,
    ) {}

    /**
     * List transfers. `?filter[direction]=sent|received`
     */
    public function index(Request $request): JsonResponse
    {
        $direction = $request->input('filter.direction');

        return $this->collection(
            EntryResource::collection($this->transfers->paginate(
                (int) Auth::id(),
                in_array($direction, ['sent', 'received'], true) ? $direction : null,
            )),
        );
    }

    /**
     * Request a transfer to another user's account.
     *
     * Creates a PENDING request. No money moves until the receiver accepts.
     */
    public function store(StoreExternalTransfer $request): JsonResponse
    {
        $transfer = $this->transfers->create(
            sender: $request->user(),
            fromAccountUuid: (string) $request->validated('from_account_uuid'),
            toAccountNumber: (string) $request->validated('to_account_number'),
            amount: (string) $request->validated('amount'),
            entryDate: (string) $request->validated('entry_date'),
            remarks: $request->validated('remarks'),
            idempotencyKey: $request->header('Idempotency-Key'),
        );

        return $this->resource(new EntryResource($transfer), (string) __('transfer.requested'), 201);
    }

    /**
     * Show a transfer. Either party may read it.
     */
    public function show(Entry $externalTransfer): JsonResponse
    {
        $this->assertParty($externalTransfer);

        return $this->resource(new EntryResource($externalTransfer));
    }

    /**
     * Accept — the only action that moves money.
     */
    public function accept(Request $request, Entry $externalTransfer): JsonResponse
    {
        $validated = $request->validate(['to_account_uuid' => ['required', 'uuid']]);

        $transfer = $this->transfers->accept(
            $externalTransfer,
            $request->user(),
            (string) $validated['to_account_uuid'],
        );

        return $this->resource(new EntryResource($transfer), (string) __('transfer.accepted'));
    }

    /**
     * Reject.
     */
    public function reject(Request $request, Entry $externalTransfer): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $transfer = $this->transfers->reject($externalTransfer, $request->user(), $validated['reason'] ?? null);

        return $this->resource(new EntryResource($transfer), (string) __('transfer.rejected'));
    }

    /**
     * Cancel — sender only, while still pending.
     */
    public function cancel(Request $request, Entry $externalTransfer): JsonResponse
    {
        $transfer = $this->transfers->cancel($externalTransfer, $request->user());

        return $this->resource(new EntryResource($transfer), (string) __('transfer.cancelled'));
    }

    /**
     * Pending count, for the tab badge.
     */
    public function pendingCount(): JsonResponse
    {
        return $this->success(['count' => $this->transfers->pendingCountFor((int) Auth::id())]);
    }

    /**
     * 404 for a stranger, not 403 — a 403 would confirm the transfer exists.
     */
    private function assertParty(Entry $transfer): void
    {
        $userId = (int) Auth::id();

        abort_unless(
            $transfer->user_id === $userId || $transfer->counterparty_user_id === $userId,
            404,
        );
    }
}
