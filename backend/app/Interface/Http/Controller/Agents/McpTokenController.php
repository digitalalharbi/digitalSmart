<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Agents;

use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Tokens for AI clients. The plain token is shown once, at creation, and never again.
 *
 * Issued to the signed-in person and acting as them: a token cannot do what its owner cannot, and it can only reach
 * the MCP endpoint (see `RestrictMcpTokens`).
 */
final class McpTokenController extends Controller
{
    public function index(Request $request, AuthorizationGuard $guard): JsonResponse
    {
        $guard->ensure(Permission::AnalyticsView);

        $tokens = $request->user()?->tokens()->where('abilities', 'like', '%mcp%')->latest()->get() ?? collect();

        return response()->json(['data' => $tokens->map(static fn (PersonalAccessToken $t): array => [
            'id' => (string) $t->getKey(),
            'name' => $t->name,
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function store(Request $request, AuthorizationGuard $guard): JsonResponse
    {
        $guard->ensure(Permission::AnalyticsView);

        $v = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $token = $request->user()?->createToken((string) $v['name'], ['mcp']);

        return response()->json(['data' => ['token' => $token?->plainTextToken, 'name' => $v['name']]], 201);
    }

    public function destroy(Request $request, string $token, AuthorizationGuard $guard): JsonResponse
    {
        $guard->ensure(Permission::AnalyticsView);

        $request->user()?->tokens()->where('id', $token)->where('abilities', 'like', '%mcp%')->delete();

        return response()->json(null, 204);
    }
}
