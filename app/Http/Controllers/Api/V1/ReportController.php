<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\ReportService;
use App\Traits\ApiResponser;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * @tags Reports
 */
#[Group('Reports', weight: 50)]
final class ReportController extends Controller
{
    use ApiResponser;

    public function __construct(
        private readonly ReportService $reports,
    ) {}

    /**
     * Day report.
     */
    #[QueryParameter('date', description: 'Y-m-d, defaults to today')]
    #[QueryParameter('account_uuid')]
    public function day(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'account_uuid' => ['nullable', 'uuid'],
        ]);

        if (isset($validated['account_uuid'])) {
            $this->ownedAccount($request, $validated['account_uuid']);
        }

        return $this->success($this->reports->day(
            (int) Auth::id(),
            $validated['date'] ?? Carbon::today()->toDateString(),
            $validated['account_uuid'] ?? null,
        ));
    }

    /**
     * Account report: header totals plus the paginated statement.
     */
    public function account(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_uuid' => ['required', 'uuid'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $account = $this->ownedAccount($request, $validated['account_uuid']);

        $statement = $this->reports->statement($account, $validated['date_from'], $validated['date_to']);

        return $this->success([
            'summary' => $this->reports->accountSummary($account, $validated['date_from'], $validated['date_to']),
            'statement' => $statement->items(),
        ], null, 200, [
            'next_cursor' => $statement->nextCursor()?->encode(),
            'prev_cursor' => $statement->previousCursor()?->encode(),
            'has_more' => $statement->hasMorePages(),
        ]);
    }

    /**
     * Summary across every account.
     */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        return $this->success($this->reports->summary(
            (int) Auth::id(),
            $validated['date_from'],
            $validated['date_to'],
        ));
    }

    /**
     * Dashboard time series.
     */
    #[QueryParameter('period', description: '7d | 30d | 90d | 1y')]
    public function chart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:7d,30d,90d,1y'],
            'account_uuid' => ['nullable', 'uuid'],
        ]);

        if (isset($validated['account_uuid'])) {
            $this->ownedAccount($request, $validated['account_uuid']);
        }

        $days = match ($validated['period'] ?? '30d') {
            '7d' => 7,
            '90d' => 90,
            '1y' => 365,
            default => 30,
        };

        return $this->success([
            'period' => $validated['period'] ?? '30d',
            'series' => $this->reports->chart(
                (int) Auth::id(),
                Carbon::today()->subDays($days)->toDateString(),
                Carbon::today()->toDateString(),
                $validated['account_uuid'] ?? null,
            ),
        ]);
    }

    /**
     * Resolve an account the caller actually owns.
     *
     * A filter naming someone else's account is a 404, not a 200 with empty
     * data — an empty result would still confirm the uuid exists.
     */
    private function ownedAccount(Request $request, string $uuid): Account
    {
        $account = Account::query()
            ->ownedBy((int) $request->user()->id)
            ->where('uuid', $uuid)
            ->first();

        abort_if($account === null, 404);

        return $account;
    }
}
