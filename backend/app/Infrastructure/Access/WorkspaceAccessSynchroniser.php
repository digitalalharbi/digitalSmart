<?php

declare(strict_types=1);

namespace App\Infrastructure\Access;

use App\Application\Access\Contract\AccessSynchroniser;
use App\Infrastructure\Persistence\Eloquent\Model\Workspace;
use App\Infrastructure\Workspace\WorkspaceProvisioner;

final readonly class WorkspaceAccessSynchroniser implements AccessSynchroniser
{
    public function __construct(private WorkspaceProvisioner $provisioner) {}

    public function syncCatalogue(): void
    {
        $this->provisioner->syncPermissionCatalogue();
    }

    /** @return list<string> */
    public function syncSystemRoles(?string $workspaceId = null): array
    {
        $query = Workspace::query();

        if ($workspaceId !== null && $workspaceId !== '') {
            $query->whereKey($workspaceId);
        }

        $names = [];

        foreach ($query->get() as $workspace) {
            $this->provisioner->createSystemRoles($workspace);
            $names[] = (string) $workspace->getAttribute('name');
        }

        return $names;
    }
}
