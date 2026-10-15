<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Project;

use App\Application\Project\Query\GetProjectReadiness;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;

final class ReadinessController extends Controller
{
    public function show(string $project, GetProjectReadiness $readiness): JsonResponse
    {
        return response()->json(['data' => $readiness($project)]);
    }
}
