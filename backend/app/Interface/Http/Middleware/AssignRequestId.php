<?php

declare(strict_types=1);

namespace App\Interface\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id for every request.
 *
 * Shared by logs, queued jobs and audit entries so one client action can be
 * traced end to end. An inbound id is accepted only if it looks like a UUID, so
 * a caller cannot inject arbitrary text into log lines.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $requestId = is_string($incoming) && Str::isUuid($incoming) ? $incoming : (string) Str::uuid7();

        $request->headers->set(self::HEADER, $requestId);
        $request->attributes->set('request_id', $requestId);
        Log::shareContext(['request_id' => $requestId]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
