<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Workspace;

use App\Application\Workspace\Query\ListRoles;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;

/** Who may do what, as the product actually enforces it. Read-only. */
final class RoleController extends Controller
{
    public function index(ListRoles $roles): JsonResponse
    {
        return response()->json(['data' => $roles()]);
    }
}
