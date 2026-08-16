<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Entry\StoreEntry;
use App\Http\Resources\Entry\Resource as EntryResource;
use App\Models\Entry;
use App\Services\EntryService;
use App\Traits\ApiResponser;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * @tags Entries
 */
#[Group('Entries', weight: 30)]
final class EntryController extends Controller
{
    use ApiResponser, AuthorizesRequests;

    public function __construct(
        private readonly EntryService $entryService,
    ) {}

    /**
     * List entries.
     *
     * Cursor paginated with no total count — COUNT(*) over a real ledger is the
     * query that takes the database down.
     */
    #[QueryParameter('filter[from_account_id]')]
    #[QueryParameter('filter[type]')]
    #[QueryParameter('filter[entry_date]')]
    #[QueryParameter('cursor')]
    public function index(): JsonResponse
    {
        return $this->collection(
            EntryResource::collection($this->entryService->paginate((int) Auth::id())),
        );
    }

    /**
     * Create an entry.
     *
     * Requires an `Idempotency-Key` header — the middleware refuses without
     * one. Returns the entry plus the affected accounts' new balances so the
     * client updates its cache without a second round trip.
     */
    public function store(StoreEntry $request): JsonResponse
    {
        $result = $this->entryService->create($request->toData());

        return $this->success([
            'entry' => new EntryResource($result['entry']),
            'balances' => $result['balances'],
        ], (string) __('entry.created'), 201);
    }

    /**
     * Show an entry.
     */
    public function show(Entry $entry): JsonResponse
    {
        $this->authorize('view', $entry);

        $entry->load(['fromAccount', 'toAccount', 'category', 'balances']);

        return $this->resource(new EntryResource($entry));
    }

    /**
     * Delete an entry.
     *
     * Soft delete, synchronous balance reversal, then an async replay of the
     * later snapshots.
     */
    public function destroy(Entry $entry): JsonResponse
    {
        $this->authorize('delete', $entry);

        $this->entryService->delete($entry);

        return $this->success(null, (string) __('entry.deleted'));
    }
}
