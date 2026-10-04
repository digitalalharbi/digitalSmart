<?php

declare(strict_types=1);

namespace App\Application\Client\Query;

use App\Application\Client\Contract\ClientReadModel;
use App\Application\Client\DTO\ClientView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Client\ClientId;

final readonly class GetClient
{
    public function __construct(
        private ClientReadModel $clients,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    public function __invoke(ClientId $clientId): ClientView
    {
        $this->guard->ensure(Permission::ClientsView);

        $client = $this->clients->find($this->actor->workspaceId(), $clientId);

        // A client in another workspace is indistinguishable from one that does
        // not exist. That is deliberate.
        return $client ?? throw ResourceNotFound::of('Client');
    }
}
