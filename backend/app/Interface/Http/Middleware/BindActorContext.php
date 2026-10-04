<?php

declare(strict_types=1);

namespace App\Interface\Http\Middleware;

use App\Application\Identity\Contract\IdentityReadModel;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the acting user and their active workspace for the request.
 *
 * The workspace comes from the session, never from a request parameter: it is
 * the key every scoped query filters on, so letting a client name it would make
 * tenant isolation a client-side concern. The stored value is re-verified
 * against live membership on every request, so revoking access takes effect
 * immediately rather than at the end of the session.
 */
final readonly class BindActorContext
{
    public const SESSION_KEY = 'active_workspace_id';

    public function __construct(
        private ActorContextBinder $binder,
        private IdentityReadModel $identities,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workspaceId = null;

        if ($user !== null) {
            $userId = new UserId((string) $user->getAuthIdentifier());
            $workspaceId = $this->resolveWorkspaceId($request, $userId);

            if ($workspaceId instanceof WorkspaceId) {
                $this->binder->bind($userId, $workspaceId);
            }
        }

        /*
         * No user, or no active membership of any workspace — for instance
         * after being suspended or removed mid-session. Clearing is deliberate:
         * a context left bound from earlier would carry permissions the person
         * no longer holds.
         */
        if ($workspaceId === null) {
            $this->binder->clear();
        }

        return $next($request);
    }

    private function resolveWorkspaceId(Request $request, UserId $userId): ?WorkspaceId
    {
        if ($request->hasSession()) {
            $stored = $request->session()->get(self::SESSION_KEY);

            if (is_string($stored) && $stored !== '') {
                $candidate = new WorkspaceId($stored);

                if ($this->identities->isMemberOf($userId, $candidate)) {
                    return $candidate;
                }
            }
        }

        return $this->identities->defaultWorkspaceId($userId);
    }
}
