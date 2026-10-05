<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Analytics;

use App\Application\Analytics\Query\GetAnalytics;
use App\Application\Shared\Contract\Clock;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Request\Analytics\AnalyticsQueryRequest;
use App\Interface\Http\Resource\Analytics\AnalyticsResultResource;
use Illuminate\Http\JsonResponse;

/**
 * Performance analytics for the active workspace.
 *
 * One endpoint answers scorecard, chart and table together, because they must
 * agree and the only way to guarantee that is to compute them from one query.
 * Permissions and visibility are enforced in the use case.
 */
final class AnalyticsController extends Controller
{
    public function __invoke(AnalyticsQueryRequest $request, GetAnalytics $getAnalytics, Clock $clock): JsonResponse
    {
        $result = $getAnalytics($request->toAnalyticsRequest($clock));

        return response()->json(['data' => AnalyticsResultResource::toArray($result)]);
    }
}
