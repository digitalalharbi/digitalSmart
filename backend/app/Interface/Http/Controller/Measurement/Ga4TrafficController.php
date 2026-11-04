<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Measurement;

use App\Application\Measurement\DTO\Ga4TrafficQuery;
use App\Application\Measurement\Query\GetGa4Traffic;
use App\Application\Shared\Contract\Clock;
use App\Interface\Http\Controller\Controller;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Stored GA4 acquisition traffic: totals, comparison, trend and a grouped, server-paginated table. */
final class Ga4TrafficController extends Controller
{
    public function __invoke(Request $request, GetGa4Traffic $traffic, Clock $clock): JsonResponse
    {
        $valid = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'until' => ['sometimes', 'date_format:Y-m-d'],
            'group' => ['sometimes', 'in:channel,source_medium'],
            'sort' => ['sometimes', 'in:sessions,engaged_sessions,page_views'],
            'direction' => ['sometimes', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50'],
            'client_id' => ['sometimes', 'uuid'],
            'project_id' => ['sometimes', 'uuid'],
            'source_id' => ['sometimes', 'uuid'],
        ]);

        /* Yesterday by default: today is still accumulating, and a partial last day reads as a drop. */
        $until = isset($valid['until'])
            ? new DateTimeImmutable($valid['until'])
            : $clock->now()->modify('-1 day')->setTime(0, 0);
        $from = isset($valid['from']) ? new DateTimeImmutable($valid['from']) : $until->modify('-27 days');

        if ($from > $until) {
            return response()->json(['message' => 'The period ends before it starts.', 'code' => 'invalid_period'], 422);
        }

        if ($from->diff($until)->days > 365) {
            return response()->json(['message' => 'A period is at most a year.', 'code' => 'period_too_long'], 422);
        }

        $view = $traffic(new Ga4TrafficQuery(
            from: $from,
            until: $until,
            group: (string) ($valid['group'] ?? 'channel'),
            sort: (string) ($valid['sort'] ?? 'sessions'),
            direction: (string) ($valid['direction'] ?? 'desc'),
            page: (int) ($valid['page'] ?? 1),
            perPage: (int) ($valid['per_page'] ?? 10),
            clientId: $valid['client_id'] ?? null,
            projectId: $valid['project_id'] ?? null,
            sourceId: $valid['source_id'] ?? null,
        ));

        return response()->json([
            'data' => [
                'period' => ['from' => $view->from, 'until' => $view->until],
                'previous_period' => ['from' => $view->previousFrom, 'until' => $view->previousUntil],
                'group' => $view->group,
                'totals' => $view->totals,
                'previous' => $view->previous,
                'change' => $view->change,
                'trend' => $view->trend,
                'rows' => $view->rows,
                'quality' => $view->quality,
                'sources' => $view->sources,
                /* Said in the payload, not only in the docs: a reader who sums the rows will want users. */
                'users' => [
                    'available' => false,
                    'reason' => 'Users are not additive across days or channels, so they are not shown at this grain.',
                ],
            ],
            'meta' => ['page' => $view->page, 'per_page' => $view->perPage, 'total' => $view->rowCount],
        ]);
    }
}
