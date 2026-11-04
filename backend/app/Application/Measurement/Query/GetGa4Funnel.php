<?php

declare(strict_types=1);

namespace App\Application\Measurement\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Measurement\Contract\Ga4FunnelReadModel;
use App\Application\Measurement\Contract\MeasurementSourceRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Integration\Provider;
use DateTimeImmutable;

/**
 * The e-commerce event funnel of the GA4 properties the reader may see.
 *
 * Scoped exactly like the traffic read: `MeasurementSourceRepository::visibleTo` and the same visibility, a foreign source id is
 * "not found", and `analytics.view` is required. The result is event counts with the rate between adjacent stages computed only
 * when both exist and the earlier one is positive, and every rate is named an event ratio — never a conversion rate of users.
 */
final readonly class GetGa4Funnel
{
    public function __construct(
        private MeasurementSourceRepository $sources,
        private Ga4FunnelReadModel $funnel,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /**
     * @return array{stages: list<array{event: string, count: ?int, ratio_from_previous: ?float}>, days_present: int, days_in_period: int, source: ?string, time_zones: list<string>, sources: list<array{id: string, name: string, client_name: string}>}
     */
    public function __invoke(DateTimeImmutable $from, DateTimeImmutable $until, ?string $clientId = null, ?string $projectId = null, ?string $sourceId = null): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = array_values(array_filter(
            $this->sources->visibleTo($workspaceId, $this->visibility->visibleProjectIds(), $clientId, $projectId),
            static fn ($source): bool => $source->provider === Provider::GoogleAnalytics && $source->isActive,
        ));

        if ($sourceId !== null) {
            $visible = array_values(array_filter($visible, static fn ($source): bool => $source->id === $sourceId));

            if ($visible === []) {
                throw ResourceNotFound::of('Measurement source');
            }
        }

        $read = $this->funnel->read($workspaceId, $visible, $from, $until);
        $stages = [];
        $previous = null;

        foreach ($read['stages'] as $stage) {
            $count = $stage['count'];
            $stages[] = [
                'event' => $stage['event'],
                'count' => $count,
                'ratio_from_previous' => $previous !== null && $count !== null && $previous > 0 ? $count / $previous : null,
            ];
            $previous = $count;
        }

        return [
            ...$read,
            'stages' => $stages,
            'sources' => array_map(static fn ($source): array => ['id' => $source->id, 'name' => $source->name, 'client_name' => $source->clientName], $visible),
        ];
    }
}
