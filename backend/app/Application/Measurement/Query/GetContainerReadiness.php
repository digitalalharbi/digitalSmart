<?php

declare(strict_types=1);

namespace App\Application\Measurement\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Measurement\Contract\MeasurementSourceRepository;
use App\Application\Measurement\Contract\TagContainerReadAdapter;
use App\Application\Measurement\DTO\ContainerReadinessView;
use App\Application\Measurement\DTO\DiscoveredContainer;
use App\Application\Measurement\DTO\MeasurementSourceView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Measurement\ContainerReadiness;
use App\Domain\Measurement\MeasurementSourceKind;

/**
 * Whether a client's tag container is actually doing anything.
 *
 * The expected measurement ids come from the client's **own analytics sources** rather than from
 * anything typed here. That is what makes the cross-check worth having: it compares what somebody
 * registered as measuring this client against what the published container actually refers to, and
 * those two drifting apart is invisible everywhere else.
 */
final readonly class GetContainerReadiness
{
    public function __construct(
        private MeasurementSourceRepository $sources,
        private TagContainerReadAdapter $containers,
        private ProviderConnectionRepository $connections,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(string $sourceId): ContainerReadinessView
    {
        /*
         * `manage` rather than `view`: this reaches the provider on the workspace's credentials.
         * Reading a container's published configuration is a setup question, not a reporting one.
         */
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $visibleProjects = $this->visibility->visibleProjectIds();
        $source = $this->sources->find($workspaceId, $visibleProjects, $sourceId);

        if ($source === null) {
            throw ResourceNotFound::of('Measurement source');
        }

        if ($source->kind !== MeasurementSourceKind::TagManager || $source->provider === null) {
            // An analytics property has no container, and saying so plainly beats an empty page.
            throw ResourceNotFound::of('Tag container');
        }

        $connection = $this->connections->agencyConnection($this->actor->workspaceId(), $source->provider);

        if ($connection === null) {
            throw ResourceNotFound::of('Tag Manager connection');
        }

        $credentials = $connection->credentials();
        $container = $this->containerFor($credentials, $source);
        $facts = $this->containers->readPublishedState($credentials, $container);

        $expected = $this->expectedMeasurementIds($workspaceId, $visibleProjects, $source);

        return new ContainerReadinessView(
            container: $container,
            hasLiveVersion: $facts->hasLiveVersion,
            liveVersionId: $facts->liveVersionId,
            hasUnpublishedChanges: $facts->hasUnpublishedChanges,
            publishedTagCount: count($facts->tags),
            issues: ContainerReadiness::assess($facts, $expected),
            measurementIdsFound: $facts->measurementIdsFound,
            measurementIdsExpected: $expected,
        );
    }

    /**
     * The container this source was registered against.
     *
     * Matched on the stored external id against what the connection can read, so a source pointing
     * at a container nobody can see any more fails here rather than producing an assessment of
     * something else.
     */
    private function containerFor(Credentials $credentials, MeasurementSourceView $source): DiscoveredContainer
    {
        foreach ($this->containers->discoverContainers($credentials) as $container) {
            if ($container->path === $source->externalId || $container->publicId === $source->externalId) {
                return $container;
            }
        }

        throw ResourceNotFound::of('Tag container');
    }

    /**
     * The measurement ids this client's analytics sources are known by.
     *
     * Read from the registered sources' metadata, where discovery put the stream's `G-` id. A
     * client with no analytics source registered yields an empty list, and the readiness check
     * then reports structure only — which is honest: there is nothing yet to cross-check against.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<string>
     */
    private function expectedMeasurementIds(
        string $workspaceId,
        ?array $visibleProjectIds,
        MeasurementSourceView $source,
    ): array {
        $ids = [];

        $siblings = $this->sources->visibleTo(
            $workspaceId,
            $visibleProjectIds,
            $source->clientId,
            $source->projectId,
        );

        foreach ($siblings as $sibling) {
            if ($sibling->kind !== MeasurementSourceKind::Analytics) {
                continue;
            }

            foreach ($this->idsIn($sibling->metadata) as $id) {
                $ids[strtoupper($id)] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Measurement ids recorded on a source's metadata.
     *
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private function idsIn(array $metadata): array
    {
        $ids = [];
        $candidates = $metadata['measurement_ids'] ?? $metadata['measurement_id'] ?? null;

        foreach (is_array($candidates) ? $candidates : [$candidates] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $ids[] = $candidate;
            }
        }

        return $ids;
    }
}
