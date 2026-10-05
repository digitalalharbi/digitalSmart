<?php

declare(strict_types=1);

namespace App\Domain\Integration\Repository;

use App\Domain\Integration\ExternalAccount;
use App\Domain\Integration\ExternalAccountId;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Workspace\WorkspaceId;

interface ExternalAccountRepository
{
    public function findInWorkspace(WorkspaceId $workspaceId, ExternalAccountId $id): ?ExternalAccount;

    public function findByExternalId(
        WorkspaceId $workspaceId,
        ProviderConnectionId $connectionId,
        string $externalId,
    ): ?ExternalAccount;

    public function save(ExternalAccount $account): void;

    /** @return list<ExternalAccount> */
    public function forConnection(WorkspaceId $workspaceId, ProviderConnectionId $connectionId): array;
}
