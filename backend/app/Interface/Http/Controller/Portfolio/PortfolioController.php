<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Portfolio;

use App\Application\Portfolio\Query\GetPortfolio;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;

/** The agency's portfolio, or one client's. A client user has neither: `analytics.view` is not theirs. */
final class PortfolioController extends Controller
{
    public function agency(GetPortfolio $portfolio): JsonResponse
    {
        return response()->json(['data' => $portfolio()]);
    }

    public function client(string $client, GetPortfolio $portfolio): JsonResponse
    {
        return response()->json(['data' => $portfolio($client)]);
    }
}
