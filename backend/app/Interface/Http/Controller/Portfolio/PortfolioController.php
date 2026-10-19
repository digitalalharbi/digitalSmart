<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Portfolio;

use App\Application\Advertising\Query\GetCampaignControl;
use App\Application\Portfolio\Query\GetPortfolio;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The agency's portfolio, or one client's. A client user has neither: `analytics.view` is not theirs. */
final class PortfolioController extends Controller
{
    public function agency(Request $request, GetPortfolio $portfolio): JsonResponse
    {
        return response()->json(['data' => $portfolio(null, $this->days($request))]);
    }

    public function client(Request $request, string $client, GetPortfolio $portfolio): JsonResponse
    {
        return response()->json(['data' => $portfolio($client, $this->days($request))]);
    }

    private function days(Request $request): ?int
    {
        $days = $request->integer('days');

        return in_array($days, GetCampaignControl::WINDOWS, true) ? $days : null;
    }
}
