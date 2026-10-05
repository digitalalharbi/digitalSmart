<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Budget;

use App\Application\Budget\Query\Forecast;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Request\Budget\ForecastRequest;
use App\Interface\Http\Resource\Budget\ForecastResource;
use Illuminate\Http\JsonResponse;

final class ForecastController extends Controller
{
    /**
     * Budget → results, or target → budget.
     *
     * A POST despite being a read, because the request carries a provider list, a scope
     * and a target that do not belong in a URL — and because nothing is stored, a reader
     * can try as many numbers as they like.
     */
    public function __invoke(ForecastRequest $request, Forecast $forecast): JsonResponse
    {
        ['benchmarks' => $benchmarks, 'forecasts' => $forecasts] = $forecast($request->toInput());

        return response()->json(['data' => ForecastResource::toArray($benchmarks, $forecasts)]);
    }
}
