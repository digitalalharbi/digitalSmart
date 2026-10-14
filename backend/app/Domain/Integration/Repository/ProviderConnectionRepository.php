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

    /**
     * Every workspace that has configured this provider.
     *
     * The one question here that is not workspace-scoped, and it has one caller: the commissioning readout,
     * which is run from a shell by an operator holding the credentials rather than by a person inside a
     * workspace. It returns ids only — nothing about the connection, and certainly no credential.
     *
     * @return list<WorkspaceId>
     */
    public function workspacesWith(Provider $provider): array;

    public function save(ProviderConnection $connection): void;

    public function remove(ProviderConnection $connection): void;
}
