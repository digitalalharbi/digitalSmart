<?php

declare(strict_types=1);

namespace App\Application\Measurement\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Measurement\Contract\Ga4TrafficReadModel;
use App\Application\Measurement\Contract\MeasurementSourceRepository;
use App\Application\Measurement\DTO\Ga4TrafficQuery;
use App\Application\Measurement\DTO\Ga4TrafficView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Integration\Provider;

/**
 * GA4 acquisition traffic for the sources the reader may see.
 *
 * Scope comes from the same visibility rule every measurement read uses (`MeasurementSourceRepository::visibleTo`),
 * so a media buyer on no project, or a project-limited role, gets only the properties of the clients they work for.
 * A source id in the query that is not among them is "not found", never an empty result: a 200 would confirm that the
 * property exists in someone else's workspace.
 */
final readonly class GetGa4Traffic
{
    public function __construct(
        private MeasurementSourceRepository $sources,
        private Ga4TrafficReadModel $traffic,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(Ga4TrafficQuery $query): Ga4TrafficView
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = array_values(array_filter(
            $this->sources->visibleTo($workspaceId, $this->visibility->visibleProjectIds(), $query->clientId, $query->projectId),
            static fn ($source): bool => $source->provider === Provider::GoogleAnalytics && $source->isActive,
        ));

        if ($query->sourceId !== null) {
            $visible = array_values(array_filter($visible, static fn ($source): bool => $source->id === $query->sourceId));

            if ($visible === []) {
                throw ResourceNotFound::of('Measurement source');
            }
        }

        return $this->traffic->read($workspaceId, $visible, $query);
    }
}
