<?php

declare(strict_types=1);

namespace App\Application\Tracking\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\TrackingHealthReadModel;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Domain\Access\Permission;
use DateTimeImmutable;

/** The debug console: recent events for a project, of every class, so a test can be watched. */
final readonly class ListDebugEvents
{
    public function __construct(
        private TrackingHealthReadModel $read,
        private TrackingLinkRepository $links,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<array<string, mixed>> */
    public function __invoke(string $projectId, ?string $touchpointId, ?DateTimeImmutable $after): array
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;

        if ($this->links->clientOfProject($workspaceId, $this->visibility->visibleProjectIds(), $projectId) === null) {
            throw ResourceNotFound::of('Project');
        }

        return $this->read->recentEvents($workspaceId, $projectId, $touchpointId, $after, 100);
    }
}
