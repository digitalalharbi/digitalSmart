<?php

declare(strict_types=1);

namespace App\Application\Workspace\Query;

use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Workspace\Contract\RoleReadModel;
use App\Domain\Access\Permission;

final readonly class ListRoles
{
    public function __construct(
        private RoleReadModel $roles,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /**
     * @return array{
     *     roles: list<array{slug: string, name: string, description: string|null, is_system: bool, members: int, permissions: list<string>}>,
     *     permissions: list<array{slug: string, group: string, description: string}>
     * }
     */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::UsersView);

        return [
            'roles' => $this->roles->rolesFor($this->actor->workspaceId()),
            'permissions' => $this->roles->catalogue(),
        ];
    }
}
