<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Measurement;

use App\Application\Measurement\Query\GetAgencyTrackingHealth;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;

final class AgencyTrackingController extends Controller
{
    public function show(GetAgencyTrackingHealth $health): JsonResponse
    {
        return response()->json(['data' => $health()]);
    }
}
