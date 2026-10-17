<?php

declare(strict_types=1);

namespace App\Application\Workspace\Contract;

use App\Domain\Workspace\WorkspaceId;

/**
 * What a role is: the permissions it holds and how many people hold it here.
 *
 * Read-only on purpose. Roles are templates in code and permissions are enforcement points in code; showing
 * them lets an administrator answer "what can a media buyer do" without reading a migration, and changing them
 * would be a deployment, not a form.
 */
interface RoleReadModel
{
    /**
     * @return list<array{slug: string, name: string, description: string|null, is_system: bool, members: int, permissions: list<string>}>
     */
    public function rolesFor(WorkspaceId $workspaceId): array;

    /** @return list<array{slug: string, group: string, description: string}> */
    public function catalogue(): array;
}
