<?php

declare(strict_types=1);

namespace App\Application\Measurement\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Measurement\Contract\EventDefinitionRepository;
use App\Application\Measurement\DTO\EventDefinitionView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

final readonly class ListEventDefinitions
{
    public function __construct(
        private EventDefinitionRepository $definitions,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<EventDefinitionView> */
    public function __invoke(?string $clientId = null, ?string $projectId = null): array
    {
        $this->guard->ensure(Permission::TrackingView);

        return $this->definitions->visibleTo(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $clientId,
            $projectId,
        );
    }
}
