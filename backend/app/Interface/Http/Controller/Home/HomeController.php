<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Home;

use App\Application\Home\Query\GetHomeProfiles;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;

final class HomeController extends Controller
{
    public function show(GetHomeProfiles $profiles): JsonResponse
    {
        return response()->json(['data' => $profiles()]);
    }
}
