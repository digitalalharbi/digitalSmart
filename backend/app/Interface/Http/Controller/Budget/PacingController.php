<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Budget;

use App\Application\Budget\DTO\PacingView;
use App\Application\Budget\Query\GetBudgetPacing;
use App\Application\Budget\Query\ListBudgetAlerts;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Budget\PacingResource;
use Illuminate\Http\JsonResponse;

final class PacingController extends Controller
{
    public function show(string $budget, GetBudgetPacing $pacing): JsonResponse
    {
        return response()->json(['data' => PacingResource::toArray($pacing($budget))]);
    }

    /**
     * The approved budgets that need looking at today.
     *
     * Derived on every request rather than stored, so it can never disagree with the
     * pacing screen it links to.
     */
    public function alerts(ListBudgetAlerts $alerts): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (PacingView $view): array => PacingResource::toArray($view),
                $alerts(),
            ),
        ]);
    }
}
