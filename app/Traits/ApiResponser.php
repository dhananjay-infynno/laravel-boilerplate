<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\ErrorCode;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The response envelope — `docs/02-API-SPEC.md` §0.
 *
 *   { "success": true,  "message": "...", "data": {...}, "meta": {...} }
 *   { "success": false, "message": "...", "error_code": "...", "errors": {...}, "meta": {...} }
 *
 * Every client depends on this shape, so it is fixed. `error_code` is the
 * contract clients switch on; `message` is translated and will change.
 *
 * The methods are `protected`, not `private`: controllers use the trait, and
 * private members are not visible to a subclass of a class that uses it.
 */
trait ApiResponser
{
    /**
     * @param  array<string, mixed>  $meta
     */
    protected function success(
        mixed $data = null,
        ?string $message = null,
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message ?? (string) __('message.success'),
            'data' => $data,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function resource(
        JsonResource $resource,
        ?string $message = null,
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        return $this->success($resource, $message, $status, $meta);
    }

    /**
     * Merges the paginator's meta INTO the envelope rather than nesting it, so
     * clients read `meta.next_cursor` and not `data.meta.next_cursor`.
     *
     * @param  array<string, mixed>  $extraMeta  Aggregates such as total_balance
     */
    protected function collection(
        ResourceCollection $collection,
        ?string $message = null,
        array $extraMeta = [],
    ): JsonResponse {
        $response = $collection->response()->getData(true);

        $meta = array_merge(
            $this->normaliseMeta($response['meta'] ?? []),
            $extraMeta,
        );

        return response()->json(array_filter([
            'success' => true,
            'message' => $message ?? (string) __('message.success'),
            'data' => $response['data'] ?? [],
            'meta' => $meta ?: null,
        ], static fn ($value) => $value !== null), 200);
    }

    /**
     * @param  array<string, mixed>  $errors
     * @param  array<string, mixed>  $meta
     */
    protected function error(
        string $message,
        ErrorCode $code,
        ?int $status = null,
        array $errors = [],
        array $meta = [],
    ): JsonResponse {
        return response()->json(array_filter([
            'success' => false,
            'message' => $message,
            'error_code' => $code->value,
            'errors' => $errors ?: null,
            'meta' => $meta ?: null,
        ], static fn ($value) => $value !== null), $status ?? $code->status());
    }

    /**
     * Cursor paginators expose next/prev differently from length-aware ones.
     * Normalising here means every list endpoint returns the same meta shape.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function normaliseMeta(array $meta): array
    {
        // Deliberately no `total`: COUNT(*) over a real ledger is the query
        // that takes the database down. Clients paginate by cursor.
        return array_filter([
            'limit' => $meta['per_page'] ?? null,
            'next_cursor' => $meta['next_cursor'] ?? null,
            'prev_cursor' => $meta['prev_cursor'] ?? null,
            'has_more' => isset($meta['next_cursor']) ? (bool) $meta['next_cursor'] : null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * Envelope for a raw cursor paginator, where no Resource is involved.
     *
     * @param  array<string, mixed>  $extraMeta
     */
    protected function cursorPage(
        CursorPaginator $paginator,
        mixed $data = null,
        ?string $message = null,
        array $extraMeta = [],
    ): JsonResponse {
        return $this->success(
            $data ?? $paginator->items(),
            $message,
            200,
            array_merge([
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ], $extraMeta),
        );
    }
}
