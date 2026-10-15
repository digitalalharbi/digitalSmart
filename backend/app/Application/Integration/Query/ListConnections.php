<?php

declare(strict_types=1);

namespace App\Application\Integration\Query;

use App\Application\Integration\Contract\ConnectionDirectory;
use App\Application\Integration\DTO\ConnectionView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

final readonly class ListConnections
{
    public function __construct(
        private ConnectionDirectory $directory,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return list<ConnectionView> */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::WorkspacesView);

        return $this->directory->connections(
            $this->actor->workspaceId()->value,
            /* The raw provider message is for the people who can act on it, not for everybody who can look. */
            $this->actor->permissions()->has(Permission::WorkspacesUpdate),
        );
    }
}
