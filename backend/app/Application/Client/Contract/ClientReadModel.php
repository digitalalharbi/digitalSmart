<?php

declare(strict_types=1);

namespace App\Application\Client\Contract;

use App\Application\Client\DTO\ClientFilters;
use App\Application\Client\DTO\ClientView;
use App\Application\Shared\DTO\Paginated;
use App\Application\Shared\DTO\Pagination;
use App\Domain\Client\ClientId;
use App\Domain\Workspace\WorkspaceId;

/**
 * Read side.
 *
 * Listings do not hydrate domain entities: a page of clients with their project
 * counts is a projection, and rebuilding aggregates to render a table would be
 * both slower and pointless.
 */
interface ClientReadModel
{
    /** @return Paginated<ClientView> */
    public function paginate(WorkspaceId $workspaceId, ClientFilters $filters, Pagination $pagination): Paginated;

    public function find(WorkspaceId $workspaceId, ClientId $clientId): ?ClientView;
}
