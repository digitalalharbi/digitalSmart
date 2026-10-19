<?php

declare(strict_types=1);

namespace App\Application\Agents\Query;

use App\Application\Agents\Contract\FindingLog;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

/**
 * What the specialist agents still see, with the figures each finding rests on, for the reader's own projects.
 *
 * Internal only: evidence carries cost and quality figures a client is not shown, and no portal route reaches this.
 */
final readonly class GetAgentFindings
{
    public function __construct(
        private FindingLog $findings,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<array<string, mixed>> */
    public function __invoke(?string $agent = null): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $rows = $this->findings->open($this->actor->workspaceId()->value, $this->visibility->visibleProjectIds(), 200);

        return array_values(array_filter($rows, static fn (array $f): bool => $agent === null || $f['agent'] === $agent));
    }
}
