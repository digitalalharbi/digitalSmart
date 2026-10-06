<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Measurement\Contract\EventDefinitionRepository;
use App\Application\Measurement\DTO\EventDefinitionInput;
use App\Application\Measurement\DTO\EventDefinitionView;
use App\Application\Measurement\DTO\EventMappingView;
use App\Domain\Measurement\NormalizedEvent;
use App\Infrastructure\Persistence\Eloquent\Model\EventDefinition;
use App\Infrastructure\Persistence\Eloquent\Model\EventMapping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final readonly class EloquentEventDefinitionRepository implements EventDefinitionRepository
{
    /** @var list<string> */
    private const RELATIONS = ['mappings', 'mappings.source:id,name'];

    /** @return list<EventDefinitionView> */
    public function visibleTo(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId = null,
        ?string $projectId = null,
    ): array {
        $query = $this->visibleQuery($workspaceId, $visibleProjectIds)->with(self::RELATIONS);

        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }

        if ($projectId !== null) {
            /*
             * A project's own conversions and the client-level ones it inherits. A client that
             * defines "Lead" once should not have to redefine it per project.
             */
            $query->where(function (Builder $scoped) use ($projectId): void {
                $scoped->where('project_id', $projectId)->orWhereNull('project_id');
            });
        }

        $definitions = $query->orderByDesc('is_primary')->orderBy('name')->get();

        return array_values(
            $definitions->map(fn (EventDefinition $d): EventDefinitionView => $this->toView($d))->all(),
        );
    }

    public function find(string $workspaceId, ?array $visibleProjectIds, string $id): ?EventDefinitionView
    {
        $definition = $this->visibleQuery($workspaceId, $visibleProjectIds)
            ->with(self::RELATIONS)
            ->whereKey($id)
            ->first();

        return $definition instanceof EventDefinition ? $this->toView($definition) : null;
    }

    public function create(string $workspaceId, string $actorId, EventDefinitionInput $input): EventDefinitionView
    {
        return DB::transaction(function () use ($workspaceId, $actorId, $input): EventDefinitionView {
            $definition = EventDefinition::query()->create([
                ...$this->attributes($workspaceId, $input),
                'created_by' => $actorId,
            ]);

            $this->replaceMappings($workspaceId, (string) $definition->getKey(), $input);

            return $this->toView($this->reload($definition));
        });
    }

    public function update(string $workspaceId, string $id, EventDefinitionInput $input): EventDefinitionView
    {
        return DB::transaction(function () use ($workspaceId, $id, $input): EventDefinitionView {
            /** @var EventDefinition $definition */
            $definition = EventDefinition::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($id)
                ->firstOrFail();

            $definition->update($this->attributes($workspaceId, $input));
            $this->replaceMappings($workspaceId, $id, $input);

            return $this->toView($this->reload($definition));
        });
    }

    public function delete(string $workspaceId, string $id): void
    {
        // Mappings cascade by the foreign key.
        EventDefinition::query()->where('workspace_id', $workspaceId)->whereKey($id)->delete();
    }

    /**
     * Replace the mapped names outright.
     *
     * The set of raw names that mean this conversion is the whole truth about it, so a name
     * somebody removed has to go — the same reasoning as a dashboard's widgets, and for the
     * same reason: a leftover mapping would keep counting towards a conversion nobody thinks
     * it belongs to.
     */
    private function replaceMappings(string $workspaceId, string $definitionId, EventDefinitionInput $input): void
    {
        EventMapping::query()->where('event_definition_id', $definitionId)->delete();

        foreach ($input->mappings as $mapping) {
            EventMapping::query()->create([
                'workspace_id' => $workspaceId,
                'event_definition_id' => $definitionId,
                'measurement_source_id' => $mapping['measurement_source_id'],
                'raw_event_name' => $mapping['raw_event_name'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function attributes(string $workspaceId, EventDefinitionInput $input): array
    {
        return [
            'workspace_id' => $workspaceId,
            'client_id' => $input->clientId,
            'project_id' => $input->projectId,
            'name' => $input->name,
            'normalized_event' => $input->normalizedEvent,
            'description' => $input->description,
            'is_conversion' => $input->isConversion,
            'is_primary' => $input->isPrimary,
            'expects_value' => $input->expectsValue,
        ];
    }

    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return Builder<EventDefinition>
     */
    private function visibleQuery(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        $query = EventDefinition::query()->where('workspace_id', $workspaceId);

        if ($visibleProjectIds === null) {
            return $query;
        }

        if ($visibleProjectIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scoped) use ($visibleProjectIds, $workspaceId): void {
            $scoped
                ->whereIn('project_id', $visibleProjectIds)
                ->orWhere(function (Builder $clientLevel) use ($visibleProjectIds, $workspaceId): void {
                    $clientLevel
                        ->whereNull('project_id')
                        ->whereIn('client_id', function (QueryBuilder $sub) use ($visibleProjectIds, $workspaceId): void {
                            $sub->select('client_id')
                                ->from('projects')
                                ->where('workspace_id', $workspaceId)
                                ->whereIn('id', $visibleProjectIds);
                        });
                });
        });
    }

    private function reload(EventDefinition $definition): EventDefinition
    {
        /** @var EventDefinition */
        return EventDefinition::query()->with(self::RELATIONS)->whereKey($definition->getKey())->firstOrFail();
    }

    private function toView(EventDefinition $definition): EventDefinitionView
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, EventMapping> $mappings */
        $mappings = $definition->getRelation('mappings');

        /** @var NormalizedEvent $normalized */
        $normalized = $definition->getAttribute('normalized_event');

        return new EventDefinitionView(
            id: (string) $definition->getKey(),
            clientId: (string) $definition->getAttribute('client_id'),
            projectId: $definition->getAttribute('project_id') === null
                ? null
                : (string) $definition->getAttribute('project_id'),
            name: (string) $definition->getAttribute('name'),
            normalizedEvent: $normalized,
            isConversion: (bool) $definition->getAttribute('is_conversion'),
            isPrimary: (bool) $definition->getAttribute('is_primary'),
            expectsValue: (bool) $definition->getAttribute('expects_value'),
            description: $definition->getAttribute('description'),
            mappings: array_values($mappings->map(static fn (EventMapping $mapping): EventMappingView => new EventMappingView(
                id: (string) $mapping->getKey(),
                rawEventName: (string) $mapping->getAttribute('raw_event_name'),
                measurementSourceId: $mapping->getAttribute('measurement_source_id') === null
                    ? null
                    : (string) $mapping->getAttribute('measurement_source_id'),
                measurementSourceName: $mapping->source?->getAttribute('name'),
            ))->all()),
        );
    }
}
