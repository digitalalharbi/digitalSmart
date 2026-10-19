<?php

declare(strict_types=1);

namespace App\Application\Agents\Command;

use App\Application\Agents\Agent\AgentOrchestrator;
use App\Application\Agents\Agent\AgentRouter;
use App\Application\Agents\Query\GetAgentFindings;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

/**
 * A person's question, sent to the agents that own it, answered with what those agents found — in the person's scope.
 *
 * The agents run as the workspace's agent identity (their own short role, never the asker's), and the answer is read
 * back through the asker's own visibility: a project manager on one client asks and sees that client's findings,
 * however much the agents looked at. The question is routed, never executed — words in it are matched to agents and
 * otherwise ignored, so "pause everything" in a question pauses nothing.
 */
final readonly class AskAgents
{
    public function __construct(
        private AgentOrchestrator $agents,
        private GetAgentFindings $findings,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return array{agents: list<string>, proposed: int, findings: list<array<string, mixed>>} */
    public function __invoke(string $question): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $chosen = AgentRouter::route(mb_substr($question, 0, 500));
        $result = $this->agents->run($chosen, $this->actor->workspaceId()->value);
        $specialists = array_values(array_intersect($chosen, ['creative', 'leads', 'reports', 'operations']));

        $found = array_values(array_filter(
            ($this->findings)(),
            static fn (array $f): bool => in_array($f['agent'], $specialists, true),
        ));

        return ['agents' => $chosen, 'proposed' => $result['proposed'], 'findings' => array_slice($found, 0, 30)];
    }
}
