<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Commerce;

use App\Application\Commerce\Query\GetRevenueReconciliation;
use App\Application\Commerce\Query\GetStoreAnalytics;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Interface\Http\Controller\Controller;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One client's sales according to each authority, with every claim's difference from the store.
 *
 * A client is required: a reconciliation across clients would be a sum of unrelated shops.
 */
final class RevenueReconciliationController extends Controller
{
    public function __invoke(Request $request, GetRevenueReconciliation $reconciliation, AuthorizationGuard $guard, Clock $clock): JsonResponse
    {
        // Before validation, as on the store analytics read: an unauthorised caller is refused, not coached.
        $guard->ensure(GetStoreAnalytics::PERMISSION);

        $valid = $request->validate([
            'client_id' => ['required', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'until' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $until = isset($valid['until']) ? new DateTimeImmutable($valid['until']) : $clock->now()->modify('-1 day')->setTime(0, 0);
        $from = isset($valid['from']) ? new DateTimeImmutable($valid['from']) : $until->modify('-27 days');

        if ($from > $until) {
            return response()->json(['message' => 'The period ends before it starts.', 'code' => 'invalid_period'], 422);
        }

        if ($from->diff($until)->days > 365) {
            return response()->json(['message' => 'A period is at most a year.', 'code' => 'period_too_long'], 422);
        }

        return response()->json(['data' => [
            'period' => ['from' => $from->format('Y-m-d'), 'until' => $until->format('Y-m-d')],
            'authority' => 'store',
            ...$reconciliation($valid['client_id'], $from, $until),
            'note' => 'The store invoiced these sales, so it is the authority. Every other row is a claim shown with its difference from the store; the sources are never added together, and a difference is absent where either side is.',
        ]]);
    }
}
