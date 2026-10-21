<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Analytics;

use App\Application\Analytics\Query\GetCreativeIntelligence;
use App\Application\Shared\Contract\Clock;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Request\Analytics\CreativeQueryRequest;
use App\Interface\Http\Resource\Analytics\CreativeIntelligenceResource;
use Illuminate\Http\JsonResponse;

/** `GET /api/analytics/creatives` — how the workspace's creatives performed. A thin edge: everything is in the use case. */
final class CreativeIntelligenceController extends Controller
{
    public function __invoke(CreativeQueryRequest $request, GetCreativeIntelligence $creatives, Clock $clock): JsonResponse
    {
        $query = $request->analytics($clock);

        $result = $creatives(
            range: $query->range,
            filters: $query->filters,
            grain: $request->grain(),
            format: $request->creativeFormat(),
            verdict: $request->verdict(),
            limit: $request->integer('limit', 24),
            offset: $request->integer('offset', 0),
        );

        return response()->json(['data' => CreativeIntelligenceResource::toArray($result)]);
    }
}
