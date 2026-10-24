<?php

declare(strict_types=1);

namespace App\Interface\Http\Middleware;

use App\Application\Saas\EntitlementGuard;
use App\Application\Shared\Contract\ActorContext;
use App\Domain\Saas\Feature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `feature:<key>` on a route: the workspace must have the feature, whoever is asking.
 *
 * Enforced on the server for every caller, beside (not instead of) the permission each use case checks. Hiding a menu item is
 * a courtesy; this is the rule. Runs after the actor is bound, so it reads the workspace the request is acting in.
 */
final readonly class EnsureFeature
{
    public function __construct(
        private EntitlementGuard $entitlements,
        private ActorContext $actor,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if ($this->actor->isBound()) {
            $this->entitlements->ensureFeature(Feature::from($feature));
        }

        return $next($request);
    }
}
