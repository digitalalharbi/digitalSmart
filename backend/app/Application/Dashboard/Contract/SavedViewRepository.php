<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Contract;

use App\Application\Dashboard\DTO\SavedViewInput;
use App\Application\Dashboard\DTO\SavedViewView;

/**
 * Saved analytics queries.
 *
 * Visibility is applied on read, not filtered afterwards: a private view belonging
 * to someone else must never be in a result set at all, because a count or a name
 * leaking is still a leak.
 */
interface SavedViewRepository
{
    /**
     * Views the actor may see, newest first.
     *
     * @param  list<string>|null  $visibleProjectIds  null means every project
     * @return list<SavedViewView>
     */
    public function visibleTo(
        string $workspaceId,
        string $actorId,
        ?array $visibleProjectIds,
    ): array;

    /** @param list<string>|null $visibleProjectIds */
    public function find(string $workspaceId, string $actorId, ?array $visibleProjectIds, string $id): ?SavedViewView;

    public function create(string $workspaceId, string $actorId, SavedViewInput $input): SavedViewView;

    public function update(string $workspaceId, string $id, SavedViewInput $input): SavedViewView;

    public function delete(string $workspaceId, string $id): void;

    /** Whether the actor owns it, and may therefore change or delete it. */
    public function isOwnedBy(string $workspaceId, string $id, string $actorId): bool;

    /**
     * Make this the actor's default, clearing any previous one.
     *
     * One default per person per workspace: two would make the landing view
     * arbitrary, which is worse than having none.
     */
    public function makeDefault(string $workspaceId, string $actorId, string $id): void;

    public function touchLastUsed(string $workspaceId, string $id): void;
}
