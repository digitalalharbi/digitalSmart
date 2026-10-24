<?php

declare(strict_types=1);

namespace App\Interface\Http\Middleware;

use App\Application\Saas\EntitlementGuard;
use App\Application\Shared\Contract\ActorContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended or cancelled workspace can be read and not changed.
 *
 * Every request that writes is refused with `423 workspace_not_writable`, except the few a person needs whatever state their
 * workspace is in: signing out, changing their own password, and switching to another workspace.
 */
final readonly class EnsureWorkspaceWritable
{
    /** @var list<string> */
    private const array ALWAYS = ['auth.logout', 'auth.password', 'workspaces.activate'];

    public function __construct(
        private EntitlementGuard $entitlements,
        private ActorContext $actor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->actor->isBound()
            && ! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)
            && ! in_array($request->route()?->getName(), self::ALWAYS, true)) {
            $this->entitlements->ensureWritable();
        }

        return $next($request);
    }
}
