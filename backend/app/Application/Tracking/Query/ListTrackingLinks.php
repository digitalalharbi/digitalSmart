<?php

declare(strict_types=1);

namespace App\Application\Tracking\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\TrackingEventStore;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\DTO\TrackingLinkView;
use App\Domain\Access\Permission;
use App\Domain\Tracking\LinkStatus;

final readonly class ListTrackingLinks
{
    public function __construct(
        private TrackingLinkRepository $links,
        private TrackingEventStore $events,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<TrackingLinkView> */
    public function __invoke(?string $projectId = null, ?string $clientId = null, ?LinkStatus $status = null): array
    {
        $this->guard->ensure(Permission::TrackingView);

        $rows = $this->links->list($this->actor->workspaceId()->value, $this->visibility->visibleProjectIds(), $projectId, $clientId, $status);
        $counts = $this->events->countsFor(array_map(static fn (TrackingLinkView $l): string => $l->id, $rows));

        return array_map(
            static fn (TrackingLinkView $l): TrackingLinkView => isset($counts[$l->id]) ? $l->withCounts($counts[$l->id]) : $l,
            $rows,
        );
    }

    public function one(string $id): TrackingLinkView
    {
        $this->guard->ensure(Permission::TrackingView);

        $link = $this->links->find($this->actor->workspaceId()->value, $this->visibility->visibleProjectIds(), $id)
            ?? throw ResourceNotFound::of('Tracking link');
        $counts = $this->events->countsFor([$id]);

        return isset($counts[$id]) ? $link->withCounts($counts[$id]) : $link;
    }

    /** @return list<array{id: string, name: string, patterns: array<string, string>}> */
    public function templates(): array
    {
        $this->guard->ensure(Permission::TrackingView);

        return $this->links->templates($this->actor->workspaceId()->value);
    }
}
