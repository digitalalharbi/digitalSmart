<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Integration;

use App\Application\Integration\Query\GetIntegrationOperations;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;

final class OperationsController extends Controller
{
    public function show(GetIntegrationOperations $operations): JsonResponse
    {
        return response()->json(['data' => $operations()]);
    }
}
