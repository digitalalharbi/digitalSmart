<?php

declare(strict_types=1);

namespace App\Domain\Client\Repository;

use App\Domain\Client\Client;
use App\Domain\Client\ClientId;
use App\Domain\Shared\ValueObject\Slug;
use App\Domain\Workspace\WorkspaceId;

/**
 * Every method takes a WorkspaceId.
 *
 * Scoping is a parameter of the contract, not something a caller may forget:
 * there is deliberately no `find(ClientId)` that could reach across workspaces.
 */
interface ClientRepository
{
    public function findInWorkspace(WorkspaceId $workspaceId, ClientId $clientId): ?Client;

    public function existsWithSlug(WorkspaceId $workspaceId, Slug $slug, ?ClientId $excluding = null): bool;

    public function save(Client $client): void;

    /** Soft-delete; the client's history is retained. */
    public function remove(Client $client): void;
}
