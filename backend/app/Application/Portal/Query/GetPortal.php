<?php

declare(strict_types=1);

namespace App\Application\Portal\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Portal\Contract\PortalRepository;
use App\Application\Portal\DTO\PortalView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/** One client's portal, as the agency sees it. */
final readonly class GetPortal
{
    public function __construct(
        private PortalRepository $portals,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(string $clientId): PortalView
    {
        $this->guard->ensure(Permission::PortalView);

        return $this->portals->find(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $clientId,
        ) ?? throw ResourceNotFound::of('Client');
    }
}
