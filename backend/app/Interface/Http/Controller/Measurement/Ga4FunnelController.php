<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Measurement;

use App\Application\Measurement\Query\GetGa4Funnel;
use App\Application\Shared\Contract\Clock;
use App\Interface\Http\Controller\Controller;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The GA4 e-commerce event funnel: aggregate event counts, never a path of people. */
final class Ga4FunnelController extends Controller
{
    public function __invoke(Request $request, GetGa4Funnel $funnel, Clock $clock): JsonResponse
    {
        $valid = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'until' => ['sometimes', 'date_format:Y-m-d'],
            'client_id' => ['sometimes', 'uuid'],
            'project_id' => ['sometimes', 'uuid'],
            'source_id' => ['sometimes', 'uuid'],
        ]);

        $until = isset($valid['until']) ? new DateTimeImmutable($valid['until']) : $clock->now()->modify('-1 day')->setTime(0, 0);
        $from = isset($valid['from']) ? new DateTimeImmutable($valid['from']) : $until->modify('-27 days');

        if ($from > $until) {
            return response()->json(['message' => 'The period ends before it starts.', 'code' => 'invalid_period'], 422);
        }

        if ($from->diff($until)->days > 365) {
            return response()->json(['message' => 'A period is at most a year.', 'code' => 'period_too_long'], 422);
        }

        $result = $funnel($from, $until, $valid['client_id'] ?? null, $valid['project_id'] ?? null, $valid['source_id'] ?? null);

        return response()->json(['data' => [
            'period' => ['from' => $from->format('Y-m-d'), 'until' => $until->format('Y-m-d')],
            ...$result,
            'note' => 'These are event counts, not people: one shopper can trigger a stage several times, so a ratio between stages is not a conversion rate. Store orders, not GA4 purchases, are the authority for sales.',
        ]]);
    }
}
