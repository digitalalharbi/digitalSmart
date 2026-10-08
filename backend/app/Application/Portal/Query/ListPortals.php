<?php

declare(strict_types=1);

namespace App\Application\Portal\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Portal\Contract\PortalRepository;
use App\Application\Portal\DTO\PortalSummary;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

/**
 * Every visible client and what their portal currently exposes.
 *
 * Clients with **no portal** are in the list. A surface that showed only the configured ones would answer "which
 * clients can see their figures" and not the question an agency actually asks before a quarterly review, which is
 * "which of them still cannot".
 */
final readonly class ListPortals
{
    public function __construct(
        private PortalRepository $portals,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<PortalSummary> */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::PortalView);

        return $this->portals->summaries(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
        );
    }
}
