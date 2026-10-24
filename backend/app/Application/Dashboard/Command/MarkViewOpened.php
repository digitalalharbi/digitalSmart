<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Dashboard\Contract\SavedViewRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/**
 * Record that a saved view was opened — only a view the reader could have opened.
 *
 * The controller used to update `last_used_at` for any id in the workspace: another tenant's id answered a quiet 204, and a
 * colleague's private view could be stamped by somebody who cannot see it. The view is now found the way reading it finds it.
 */
final readonly class MarkViewOpened
{
    public function __construct(
        private SavedViewRepository $views,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(string $id): void
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $workspace = $this->actor->workspaceId()->value;

        $this->views->find($workspace, $this->actor->userId()->value, $this->visibility->visibleProjectIds(), $id)
            ?? throw ResourceNotFound::of('Saved view');

        $this->views->touchLastUsed($workspace, $id);
    }
}
