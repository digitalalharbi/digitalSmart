<?php

declare(strict_types=1);

namespace App\Domain\Integration\Repository;

use App\Domain\Client\ClientId;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Workspace\WorkspaceId;

/** Workspace-scoped by contract, as with every other repository here. */
interface ProviderConnectionRepository
{
    public function findInWorkspace(WorkspaceId $workspaceId, ProviderConnectionId $id): ?ProviderConnection;

    /**
     * The agency's own connection to a provider.
     *
     * Replaced `findForProvider`, which could only be right while a workspace held one connection per
     * platform. Callers that need the credentials behind a *particular* account, store or measurement source
     * must not use this: those rows carry `provider_connection_id`, and resolving by provider instead would
     * read a client's data with the agency's token the moment both exist.
     */
    public function agencyConnection(WorkspaceId $workspaceId, Provider $provider): ?ProviderConnection;

    /** The connection a specific client granted directly, if they granted one. */
    public function forClient(
        WorkspaceId $workspaceId,
        Provider $provider,
        ClientId $clientId,
    ): ?ProviderConnection;

    /**
     * Every connection to a provider in a workspace, agency-owned first.
     *
     * The ordering is not cosmetic: a caller that must pick one without further information should pick the
     * agency's, and leaving the order to the database would make that choice vary between rows.
     *
     * @return list<ProviderConnection>
     */
    public function listForProvider(WorkspaceId $workspaceId, Provider $provider): array;

    /**
     * Every connection in a workspace, whatever the provider.
     *
     * @return list<ProviderConnection>
     */
    public function listInWorkspace(WorkspaceId $workspaceId): array;

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
