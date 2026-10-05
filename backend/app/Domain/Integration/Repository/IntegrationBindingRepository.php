<?php

declare(strict_types=1);

namespace App\Domain\Integration\Repository;

use App\Domain\Integration\ExternalAccountId;
use App\Domain\Integration\IntegrationBindingId;
use App\Domain\Integration\ProjectIntegrationBinding;
use App\Domain\Project\ProjectId;
use App\Domain\Workspace\WorkspaceId;

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
}
