<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Contract;

use App\Application\Dashboard\DTO\DashboardInput;
use App\Application\Dashboard\DTO\DashboardView;

interface DashboardRepository
{
    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return list<DashboardView>
     */
    public function visibleTo(string $workspaceId, string $actorId, ?array $visibleProjectIds): array;

    /** @param list<string>|null $visibleProjectIds */
    public function find(
        string $workspaceId,
        string $actorId,
        ?array $visibleProjectIds,
        string $id,
    ): ?DashboardView;

    public function create(string $workspaceId, string $actorId, DashboardInput $input): DashboardView;

    /**
     * Save a dashboard and, when widgets are supplied, replace its whole set.
     *
     * Replaced rather than merged: the canvas the reader sees is the whole truth
     * about the dashboard, so a widget they deleted has to go, and diffing a set of
     * positions is a worse way to get there than sending the set.
     */
    public function update(string $workspaceId, string $id, DashboardInput $input): DashboardView;

    public function delete(string $workspaceId, string $id): void;

    /** Copy a dashboard, including its widgets, as the actor's own private draft. */
    public function duplicate(string $workspaceId, string $actorId, string $id, string $name): DashboardView;

    /**
     * Does this actor already have a dashboard by this name?
     *
     * Case-sensitive, matching the unique index exactly: a looser comparison here
     * would refuse a name the database would have accepted.
     */
    public function nameTaken(string $workspaceId, string $actorId, string $name, ?string $exceptId = null): bool;

    public function isOwnedBy(string $workspaceId, string $id, string $actorId): bool;

    public function makeDefault(string $workspaceId, string $actorId, string $id): void;

    public function touchLastViewed(string $workspaceId, string $id): void;
}
