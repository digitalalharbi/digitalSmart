<?php

declare(strict_types=1);

namespace App\Interface\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * A token issued for an AI client can reach the MCP endpoint and nothing else.
 *
 * Sanctum tokens carry the full power of their user unless something says otherwise, and an AI client is the last
 * place to leave that open. The token's one ability is `mcp`; every route but the MCP one refuses it.
 */
final class RestrictMcpTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->can('mcp') && ! $request->is('api/mcp')) {
            return response()->json([
                'message' => 'This token is for AI clients and can only use the MCP endpoint.',
                'code' => 'mcp_token_scope',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
