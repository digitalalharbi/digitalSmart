<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\System;

use App\Application\System\Query\GetSyncStatus;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;

final class SyncStatusController extends Controller
{
    /** Is `main` green, and is that what is live. Internal: needs `workspaces.view`. */
    public function __invoke(GetSyncStatus $status): JsonResponse
    {
        return response()->json(['data' => $status()]);
    }
}
