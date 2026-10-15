<?php

declare(strict_types=1);

namespace App\Application\SurfaceView\Contract;

use App\Application\SurfaceView\DTO\SurfaceViewView;
use App\Domain\Dashboard\Visibility;
use App\Domain\SurfaceView\SurfaceKind;

interface SurfaceViewRepository
{
    /**
     * Private to the actor, shared with the workspace, or shared with a project the actor can see.
     * `$visibleProjectIds` null means every project; an empty list means none.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<SurfaceViewView>
     */
    public function visibleTo(string $workspaceId, string $actorId, ?array $visibleProjectIds, SurfaceKind $surface): array;

    /** @param array<string, string|list<string>> $filters */
    public function create(
        string $workspaceId,
        string $actorId,
        SurfaceKind $surface,
        string $name,
        Visibility $visibility,
        ?string $projectId,
        array $filters,
    ): SurfaceViewView;

    public function nameTaken(string $workspaceId, string $actorId, SurfaceKind $surface, string $name): bool;

    public function isOwnedBy(string $workspaceId, string $id, string $actorId): bool;

    public function delete(string $workspaceId, string $id): void;
}
