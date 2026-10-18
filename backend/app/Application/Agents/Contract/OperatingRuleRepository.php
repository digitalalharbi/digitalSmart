<?php

declare(strict_types=1);

namespace App\Application\Agents\Contract;

interface OperatingRuleRepository
{
    /**
     * One scope's own row, unresolved. Null when that scope has set nothing.
     *
     * @return array<string, mixed>|null
     */
    public function own(string $workspaceId, ?string $clientId, ?string $projectId): ?array;

    /** The client a project belongs to, within the workspace — null if the project is not this workspace's. */
    public function clientOf(string $workspaceId, string $projectId): ?string;

    /** @param array<string, mixed> $values */
    public function save(string $workspaceId, string $actorId, ?string $clientId, ?string $projectId, array $values): void;
}
