<?php

declare(strict_types=1);

namespace App\Application\Measurement\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Measurement\Contract\EventDefinitionRepository;
use App\Application\Measurement\Contract\MeasurementSourceRepository;
use App\Application\Measurement\Contract\ObservedEventReadModel;
use App\Application\Measurement\DTO\EventDefinitionView;
use App\Application\Measurement\DTO\MeasurementSourceView;
use App\Application\Measurement\DTO\TrackingHealthView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\Permission;
use App\Domain\Measurement\DefinitionFacts;
use App\Domain\Measurement\MeasurementSourceKind;
use App\Domain\Measurement\SourceFacts;
use App\Domain\Measurement\TrackingHealth;

/**
 * What is wrong with a project's measurement.
 *
 * Derived on every read, like pacing and the budget alerts, and for the same reason: a stored
 * diagnosis is wrong the moment the next sync lands, and a stale warning teaches people to
 * ignore the list.
 *
 * The work here is assembling facts; the judgement is in the domain, which has no database
 * and no clock of its own. That split is what makes "a source that syncs but reports nothing
 * is critical" a rule somebody can read and test rather than a condition buried in a query.
 */
final readonly class GetTrackingHealth
{
    /** The trailing window the counts are read over. A fortnight of ordinary delivery. */
    private const int WINDOW_DAYS = 14;

    public function __construct(
        private MeasurementSourceRepository $sources,
        private EventDefinitionRepository $definitions,
        private ObservedEventReadModel $observed,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    public function __invoke(?string $clientId = null, ?string $projectId = null): TrackingHealthView
    {
        $this->guard->ensure(Permission::TrackingView);

        $workspaceId = $this->actor->workspaceId()->value;
        $visibleProjects = $this->visibility->visibleProjectIds();

        $sources = $this->sources->visibleTo($workspaceId, $visibleProjects, $clientId, $projectId);
        $definitions = $this->definitions->visibleTo($workspaceId, $visibleProjects, $clientId, $projectId);

        $sourceIds = array_map(
            static fn (MeasurementSourceView $source): string => $source->id,
            $sources,
        );

        $counts = $sourceIds === []
            ? []
            : $this->observed->countsByRawName($workspaceId, $sourceIds, self::WINDOW_DAYS);

        $unmapped = $sourceIds === []
            ? []
            : $this->observed->unmappedRawNames($workspaceId, $sourceIds, self::WINDOW_DAYS);

        $issues = TrackingHealth::diagnose(
            array_map(fn (MeasurementSourceView $source): SourceFacts => $this->sourceFacts($source), $sources),
            array_map(
                fn (EventDefinitionView $definition): DefinitionFacts => $this->definitionFacts($definition, $counts),
                $definitions,
            ),
            $unmapped,
            $this->clock->now(),
        );

        return new TrackingHealthView(
            sources: $sources,
            definitions: $this->withObservations($definitions, $counts),
            issues: $issues,
            windowDays: self::WINDOW_DAYS,
        );
    }

    private function sourceFacts(MeasurementSourceView $source): SourceFacts
    {
        return new SourceFacts(
            id: $source->id,
            name: $source->name,
            kind: $source->kind,
            lastSyncedAt: $source->lastSyncedAt,
            /*
             * A tag manager reports configuration, not events, so it has no "last event" to be
             * silent about. Passing its timestamp through would make every container a
             * permanent critical warning — the kind of false alarm that empties a tracking
             * page of meaning.
             */
            lastEventAt: $source->kind === MeasurementSourceKind::TagManager ? null : $source->lastEventAt,
        );
    }

    /**
     * @param  array<string, array{count: int, has_value: bool, last_date: string}>  $counts
     */
    private function definitionFacts(EventDefinitionView $definition, array $counts): DefinitionFacts
    {
        $total = 0;
        $hasValue = false;
        $names = [];

        foreach ($definition->mappings as $mapping) {
            $names[] = $mapping->rawEventName;
            $observed = $counts[$mapping->rawEventName] ?? null;

            if ($observed !== null) {
                $total += $observed['count'];
                $hasValue = $hasValue || $observed['has_value'];
            }
        }

        return new DefinitionFacts(
            id: $definition->id,
            name: $definition->name,
            normalizedEvent: $definition->normalizedEvent,
            isConversion: $definition->isConversion,
            isPrimary: $definition->isPrimary,
            expectsValue: $definition->expectsValue,
            observedCount: $total,
            hasValue: $hasValue,
            rawEventNames: $names,
        );
    }

    /**
     * The definitions again, each carrying what was actually seen.
     *
     * So a reader does not have to join the issue list against the definition list to find out
     * whether the conversion in front of them has ever fired.
     *
     * @param  list<EventDefinitionView>  $definitions
     * @param  array<string, array{count: int, has_value: bool, last_date: string}>  $counts
     * @return list<EventDefinitionView>
     */
    private function withObservations(array $definitions, array $counts): array
    {
        return array_map(
            function (EventDefinitionView $definition) use ($counts): EventDefinitionView {
                $facts = $this->definitionFacts($definition, $counts);
                $lastSeen = null;

                foreach ($definition->mappings as $mapping) {
                    $observed = $counts[$mapping->rawEventName] ?? null;

                    if ($observed !== null && ($lastSeen === null || $observed['last_date'] > $lastSeen)) {
                        $lastSeen = $observed['last_date'];
                    }
                }

                return new EventDefinitionView(
                    id: $definition->id,
                    clientId: $definition->clientId,
                    projectId: $definition->projectId,
                    name: $definition->name,
                    normalizedEvent: $definition->normalizedEvent,
                    isConversion: $definition->isConversion,
                    isPrimary: $definition->isPrimary,
                    expectsValue: $definition->expectsValue,
                    description: $definition->description,
                    mappings: $definition->mappings,
                    observedCount: $facts->observedCount,
                    lastSeenDate: $lastSeen,
                );
            },
            $definitions,
        );
    }
}
