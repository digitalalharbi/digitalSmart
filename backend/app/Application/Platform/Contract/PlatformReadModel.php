<?php

declare(strict_types=1);

namespace App\Application\Platform\Contract;

use App\Application\Platform\DTO\PlatformWorkspaceRow;

interface PlatformReadModel
{
    /** @return array{rows: list<PlatformWorkspaceRow>, total: int} */
    public function workspaces(?string $search, ?string $status, int $page, int $perPage): array;

    public function find(string $workspaceId): ?PlatformWorkspaceRow;

    public function slugTaken(string $slug): bool;

    /**
     * The commercial history of one workspace: plan, override, status and creation entries, newest first.
     *
     * @return list<array{action: string, summary: string|null, at: string}>
     */
    public function history(string $workspaceId, int $limit = 30): array;
}
