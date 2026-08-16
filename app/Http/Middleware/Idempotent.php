<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Domain\DuplicateRequestException;
use App\Exceptions\Domain\IdempotencyKeyRequiredException;
use App\Models\IdempotencyKey;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency for money-writing requests — `docs/00-MASTER-PLAN.md` §4.5.
 *
 * WHY THIS IS NOT OPTIONAL:
 *
 * The mobile app retries on flaky connections, which in this market is most of
 * them. Without a key the server cannot tell a retry from a genuine second
 * entry, so a dropped response posts the user's money twice — and they only
 * find out when their balance is wrong.
 *
 * The client generates the key ONCE when the user submits and reuses it on
 * every retry. A fresh key per attempt defeats the whole mechanism, which is
 * why the RN hook builds it in the mutation and not in the request function.
 *
 * Two distinct outcomes:
 *   - same key, same body  -> replay the stored response, create nothing
 *   - same key, DIFFERENT body -> 409. Never a legitimate retry: either a
 *     client bug or an attempt to smuggle a second payload past deduplication.
 *     Replaying would hide it.
 */
final class Idempotent
{
    private const HEADER = 'Idempotency-Key';

    /** 24h — comfortably longer than any client would keep retrying. */
    private const TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $key = $request->header(self::HEADER);

        if (! is_string($key) || trim($key) === '') {
            throw new IdempotencyKeyRequiredException;
        }

        $key = trim($key);
        $userId = (int) $user->id;
        $requestHash = $this->hashRequest($request);
        $cacheKey = "idem:{$userId}:{$key}";

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $this->replay($cached, $requestHash);
        }

        // Durable fallback: the cache may have been evicted, but a money write
        // must still not run twice.
        $record = IdempotencyKey::query()
            ->where('user_id', $userId)
            ->where('key', $key)
            ->first();

        if ($record !== null) {
            if (! hash_equals((string) $record->request_hash, $requestHash)) {
                throw new DuplicateRequestException;
            }

            if ($record->status === 'completed' && $record->response_body !== null) {
                return response()->json($record->response_body, (int) ($record->response_code ?? 200));
            }

            // Still processing — the first request is in flight. 409 rather
            // than running concurrently and racing it.
            throw new DuplicateRequestException;
        }

        IdempotencyKey::create([
            'user_id' => $userId,
            'key' => $key,
            'endpoint' => $request->method().' '.$request->path(),
            'request_hash' => $requestHash,
            'status' => 'processing',
            'expires_at' => Carbon::now()->addSeconds(self::TTL_SECONDS),
        ]);

        /** @var Response $response */
        $response = $next($request);

        // Only successful responses are replayable. Storing a 422 would pin the
        // user to their own validation error until the key expired.
        if ($response->getStatusCode() < 400) {
            $body = json_decode((string) $response->getContent(), true);

            IdempotencyKey::query()
                ->where('user_id', $userId)
                ->where('key', $key)
                ->update([
                    'status' => 'completed',
                    'response_code' => $response->getStatusCode(),
                    'response_body' => $body,
                    'updated_at' => Carbon::now(),
                ]);

            Cache::put($cacheKey, [
                'hash' => $requestHash,
                'code' => $response->getStatusCode(),
                'body' => $body,
            ], self::TTL_SECONDS);
        } else {
            // Free the key so the client can legitimately retry after fixing
            // whatever was wrong.
            IdempotencyKey::query()
                ->where('user_id', $userId)
                ->where('key', $key)
                ->delete();
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    private function replay(array $cached, string $requestHash): Response
    {
        if (! hash_equals((string) ($cached['hash'] ?? ''), $requestHash)) {
            throw new DuplicateRequestException;
        }

        return response()->json($cached['body'], (int) ($cached['code'] ?? 200));
    }

    /**
     * Hash the body, not the headers.
     *
     * Headers legitimately differ between a request and its retry (timing,
     * tracing, connection), and hashing them would turn every retry into a
     * false 409.
     */
    private function hashRequest(Request $request): string
    {
        $payload = $request->all();
        ksort($payload);

        return hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($payload));
    }
}
