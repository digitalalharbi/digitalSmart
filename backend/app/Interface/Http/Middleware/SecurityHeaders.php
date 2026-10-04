<?php

declare(strict_types=1);

namespace App\Interface\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies baseline response hardening and removes headers that leak the stack.
 *
 * The API returns JSON only, so a strict CSP that forbids everything is safe
 * here; the SPA is served separately and carries its own policy.
 */
final class SecurityHeaders
{
    /** @var array<string, string> */
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Cross-Origin-Resource-Policy' => 'same-site',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
    ];

    /** Headers that disclose implementation detail to an attacker. */
    private const STRIPPED = ['X-Powered-By', 'Server'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        foreach (self::HEADERS as $header => $value) {
            $response->headers->set($header, $value, false);
        }

        foreach (self::STRIPPED as $header) {
            $response->headers->remove($header);

            // PHP adds X-Powered-By itself (expose_php), outside Symfony's header
            // bag, so it has to be removed at the SAPI level as well.
            if (! headers_sent()) {
                header_remove($header);
            }
        }

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
