<?php

declare(strict_types=1);

namespace App\Application\Activity\Contract;

use App\Application\Activity\DTO\ActivityItem;

interface ActivityReadModel
{
    /**
     * Recent business events the reader may see, newest first.
     *
     * Reads the audit trail through an allowlist of business actions and resolves each entry to the project
     * and client it concerns, then keeps only those inside `$visibleProjectIds` (null: everything). Entries
     * that cannot be placed in a project or client are never shown to a scoped reader.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<ActivityItem>
     */
    public function recent(string $workspaceId, ?array $visibleProjectIds, int $limit, ?string $projectId = null, ?string $clientId = null): array;
}
