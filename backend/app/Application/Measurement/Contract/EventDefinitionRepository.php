<?php

declare(strict_types=1);

namespace App\Application\Measurement\Contract;

use App\Application\Measurement\DTO\EventDefinitionInput;
use App\Application\Measurement\DTO\EventDefinitionView;

interface EventDefinitionRepository
{
    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return list<EventDefinitionView>
     */
    public function visibleTo(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId = null,
        ?string $projectId = null,
    ): array;

    /** @param list<string>|null $visibleProjectIds */
    public function find(string $workspaceId, ?array $visibleProjectIds, string $id): ?EventDefinitionView;

    public function create(string $workspaceId, string $actorId, EventDefinitionInput $input): EventDefinitionView;

    /**
     * Save a definition and replace its mappings.
     *
     * Replaced rather than merged, like a dashboard's widgets: the set of raw names that mean
     * this conversion is the whole truth about it, so one somebody removed has to go.
     */
    public function update(string $workspaceId, string $id, EventDefinitionInput $input): EventDefinitionView;

    public function delete(string $workspaceId, string $id): void;
}
