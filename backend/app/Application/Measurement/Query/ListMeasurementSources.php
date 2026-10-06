<?php

declare(strict_types=1);

namespace App\Application\Measurement\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Measurement\Contract\MeasurementSourceRepository;
use App\Application\Measurement\DTO\MeasurementSourceView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

final readonly class ListMeasurementSources
{
    public function __construct(
        private MeasurementSourceRepository $sources,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<MeasurementSourceView> */
    public function __invoke(?string $clientId = null, ?string $projectId = null): array
    {
        $this->guard->ensure(Permission::TrackingView);

        return $this->sources->visibleTo(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $clientId,
            $projectId,
        );
    }
}
