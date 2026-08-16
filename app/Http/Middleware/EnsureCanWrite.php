<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Domain\SubscriptionRequiredException;
use App\Models\User;
use App\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The read-only lock — `docs/00-MASTER-PLAN.md` §4.10.
 *
 * Mounted on mutating ledger routes only. Reads, reports and exports stay
 * outside it: a lapsed subscriber keeps full visibility of their own data and
 * can always get it out. That is deliberate, not an oversight.
 *
 * Reads from cache, never the database. This runs on every write in the system,
 * and a query here would be pure overhead re-deriving a value that changes
 * roughly once a month per user.
 *
 * This is layer one of three. EntryService and AccountService re-check
 * independently — never assume the middleware ran, because a route registered
 * in the wrong group is exactly the mistake that would let it be skipped.
 */
final class EnsureCanWrite
{
    public function __construct(
        private readonly EntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            // Unauthenticated is auth:api's problem, not this middleware's.
            return $next($request);
        }

        $entitlements = $this->entitlements->for((int) $user->id);

        if (! $entitlements->canWrite) {
            // 402 — every client turns this into the paywall.
            throw new SubscriptionRequiredException($entitlements->status->value);
        }

        return $next($request);
    }
}
