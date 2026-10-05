<?php

declare(strict_types=1);

namespace App\Domain\Integration\Repository;

use App\Domain\Integration\ExternalAccountId;
use App\Domain\Integration\IntegrationBindingId;
use App\Domain\Integration\ProjectIntegrationBinding;
use App\Domain\Integration\SyncTarget;
use App\Domain\Project\ProjectId;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;

interface IntegrationBindingRepository
{
    public function find(WorkspaceId $workspaceId, IntegrationBindingId $id): ?ProjectIntegrationBinding;

    public function findForPair(
        WorkspaceId $workspaceId,
        ProjectId $projectId,
        ExternalAccountId $externalAccountId,
    ): ?ProjectIntegrationBinding;

    public function save(ProjectIntegrationBinding $binding): void;

    public function remove(ProjectIntegrationBinding $binding): void;

    /** Whether any project is still bound to this account. */
    public function countForAccount(WorkspaceId $workspaceId, ExternalAccountId $externalAccountId): int;

    /**
     * How many of those bindings actually want syncing.
     *
     * Separate from the count above, because the two answer different questions.
     * A bound-but-paused account must not be read: the isolation model says a
     * project sees only what it was explicitly given, and pausing sync is how an
     * operator says "stop pulling this client's data" without unbinding.
     */
    public function syncEnabledCountForAccount(WorkspaceId $workspaceId, ExternalAccountId $externalAccountId): int;

    /**
     * Every account at least one project syncs, optionally in one workspace.
     *
     * Returned with its workspace attached, so a scheduler does not have to
     * enumerate workspaces separately — which would be both an extra query per
     * workspace and an opportunity to scope a write wrongly.
     *
     * @return list<SyncTarget>
     */
    public function syncTargets(?WorkspaceId $workspaceId = null): array;

    /** Mark every sync-enabled binding for this account as just synced. */
    public function touchSyncedAt(
        WorkspaceId $workspaceId,
        ExternalAccountId $externalAccountId,
        DateTimeImmutable $at,
    ): void;
}
