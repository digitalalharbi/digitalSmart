<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\System;

use App\Application\System\Query\GetSystemHealth;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\SystemHealthResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class HealthController extends Controller
{
    /**
     * System health.
     *
     * Reports whether the database, cache and queue backends are serving. Returns
     * 503 when a dependency is down so uptime monitors and load balancers react,
     * while still returning the full body for humans.
     */
    public function __invoke(GetSystemHealth $getSystemHealth): JsonResponse
    {
        $report = $getSystemHealth();

        $status = $report->status()->isOperational()
            ? Response::HTTP_OK
            : Response::HTTP_SERVICE_UNAVAILABLE;

        return SystemHealthResource::make($report)->response()->setStatusCode($status);
    }
}
