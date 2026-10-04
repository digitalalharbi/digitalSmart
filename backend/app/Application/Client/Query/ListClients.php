<?php

declare(strict_types=1);

namespace App\Application\Client\Query;

use App\Application\Client\Contract\ClientReadModel;
use App\Application\Client\DTO\ClientFilters;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\DTO\Paginated;
use App\Application\Shared\DTO\Pagination;
use App\Domain\Access\Permission;

final readonly class ListClients
{
    public function __construct(
        private ClientReadModel $clients,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return Paginated<\App\Application\Client\DTO\ClientView> */
    public function __invoke(ClientFilters $filters, Pagination $pagination): Paginated
    {
        $this->guard->ensure(Permission::ClientsView);

        return $this->clients->paginate($this->actor->workspaceId(), $filters, $pagination);
    }
}
