<?php

declare(strict_types=1);

namespace App\Domain\Integration;

use App\Domain\Project\ProjectId;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;

/**
 * The explicit grant that lets one project read one external ad account.
 *
 * This is the rule that keeps one client's spend invisible to another. There is
 * no inheritance from workspace or client level, and no implicit binding: if a
 * row does not exist here, the project sees nothing from that account.
 */
final class ProjectIntegrationBinding
{
    private function __construct(
        public readonly IntegrationBindingId $id,
        public readonly WorkspaceId $workspaceId,
        public readonly ProjectId $projectId,
        public readonly ExternalAccountId $externalAccountId,
        private bool $syncEnabled,
        public readonly DateTimeImmutable $boundAt,
        private ?DateTimeImmutable $lastSyncedAt,
    ) {}

    public static function bind(
        IntegrationBindingId $id,
        WorkspaceId $workspaceId,
        ProjectId $projectId,
        ExternalAccountId $externalAccountId,
        DateTimeImmutable $at,
    ): self {
        return new self($id, $workspaceId, $projectId, $externalAccountId, true, $at, null);
    }

    /** Rehydrate from storage without replaying business rules. */
    public static function reconstitute(
        IntegrationBindingId $id,
        WorkspaceId $workspaceId,
        ProjectId $projectId,
        ExternalAccountId $externalAccountId,
        bool $syncEnabled,
        DateTimeImmutable $boundAt,
        ?DateTimeImmutable $lastSyncedAt,
    ): self {
        return new self($id, $workspaceId, $projectId, $externalAccountId, $syncEnabled, $boundAt, $lastSyncedAt);
    }

    /**
     * Pause syncing without unbinding.
     *
     * Unbinding and pausing are different intents: pausing keeps the history
     * and the relationship, and is reversible without re-establishing trust.
     */
    public function pauseSync(): void
    {
        $this->syncEnabled = false;
    }

    public function resumeSync(): void
    {
        $this->syncEnabled = true;
    }

    public function recordSync(DateTimeImmutable $at): void
    {
        $this->lastSyncedAt = $at;
    }

    public function syncEnabled(): bool
    {
        return $this->syncEnabled;
    }

    public function lastSyncedAt(): ?DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function belongsToWorkspace(WorkspaceId $workspaceId): bool
    {
        return $this->workspaceId->equals($workspaceId);
    }
}
