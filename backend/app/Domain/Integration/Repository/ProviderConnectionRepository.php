<?php

declare(strict_types=1);

namespace App\Domain\Integration\Repository;

use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Workspace\WorkspaceId;

/** Workspace-scoped by contract, as with every other repository here. */
interface ProviderConnectionRepository
{
    public function findInWorkspace(WorkspaceId $workspaceId, ProviderConnectionId $id): ?ProviderConnection;

    public function findForProvider(WorkspaceId $workspaceId, Provider $provider): ?ProviderConnection;

    public function save(ProviderConnection $connection): void;

    public function remove(ProviderConnection $connection): void;
}
