<?php

declare(strict_types=1);

namespace App\Application\Tracking\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\TrackingLinkHistory;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\DTO\LinkRevision;
use App\Domain\Access\Permission;

/**
 * What a link said, and when, with the clicks each state received.
 *
 * A click that arrived before an edit carried the values of the time. This is where that stays readable after the link
 * is changed.
 */
final readonly class GetTrackingLinkHistory
{
    public function __construct(
        private TrackingLinkRepository $links,
        private TrackingLinkHistory $history,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<LinkRevision> */
    public function __invoke(string $linkId): array
    {
        $this->guard->ensure(Permission::TrackingView);

        $workspaceId = $this->actor->workspaceId()->value;

        $this->links->find($workspaceId, $this->visibility->visibleProjectIds(), $linkId)
            ?? throw ResourceNotFound::of('Tracking link');

        return $this->history->revisions($workspaceId, $linkId);
    }
}
