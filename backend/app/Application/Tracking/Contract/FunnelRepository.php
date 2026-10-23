<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

use App\Domain\Tracking\Funnel\FunnelStage;

interface FunnelRepository
{
    /**
     * @param  list<FunnelStage>  $stages
     * @return array{id: string, project_id: string, name: string, stages: list<array<string, mixed>>}
     */
    public function save(string $workspaceId, string $projectId, ?string $id, string $name, array $stages, string $actorId): array;

    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return list<array{id: string, project_id: string, name: string, stages: list<array<string, mixed>>}>
     */
    public function list(string $workspaceId, ?array $visibleProjectIds, ?string $projectId): array;

    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return array{id: string, project_id: string, name: string, stages: list<FunnelStage>}|null
     */
    public function find(string $workspaceId, ?array $visibleProjectIds, string $id): ?array;

    public function delete(string $workspaceId, string $id): void;
}
