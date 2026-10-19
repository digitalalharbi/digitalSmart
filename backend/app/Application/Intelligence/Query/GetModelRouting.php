<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Query;

use App\Application\Intelligence\Contract\ModelCredentialStore;
use App\Application\Intelligence\Contract\ModelRouter;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

final readonly class GetModelRouting
{
    public function __construct(
        private ModelCredentialStore $store,
        private ModelRouter $router,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return array{credentials: array<string, mixed>, routing: list<array<string, string>>} */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);

        $workspaceId = $this->actor->workspaceId()->value;

        return ['credentials' => $this->store->describe($workspaceId), 'routing' => $this->router->status($workspaceId)];
    }
}
