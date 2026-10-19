<?php

declare(strict_types=1);

namespace App\Application\Agents\Command;

use App\Application\Agents\Agent\AgentOrchestrator;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

/**
 * Run this workspace's agents now, instead of waiting for the morning routine.
 *
 * They run as the agent identity, not as the administrator who pressed the button — so what they may do is the
 * agent's role, never the administrator's — and the administrator's own context is restored afterwards.
 */
final readonly class RunAgentsNow
{
    public function __construct(
        private AgentOrchestrator $agents,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private ActorContextBinder $binder,
    ) {}

    /** @return array{workspaces: int, proposed: int, findings: int, failed: int} */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);

        $user = $this->actor->userId();
        $workspace = $this->actor->workspaceId();

        try {
            return $this->agents->run('all', $workspace->value);
        } finally {
            $this->binder->bind($user, $workspace);
        }
    }
}
