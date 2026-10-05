<?php

declare(strict_types=1);

namespace App\Application\Access\Command;

use App\Application\Access\Contract\AccessSynchroniser;

/**
 * Bring every workspace's access definitions up to date with the code.
 *
 * Deliberately not permission-gated. It runs from the console, where the actor is
 * an operator with shell access to the server rather than a signed-in user — and
 * a permission check here could lock an operator out of the very command that
 * repairs broken permissions.
 */
final readonly class SyncAccessDefinitions
{
    public function __construct(private AccessSynchroniser $access) {}

    /** @return list<string> the workspaces brought up to date */
    public function __invoke(?string $workspaceId = null): array
    {
        $this->access->syncCatalogue();

        return $this->access->syncSystemRoles($workspaceId);
    }
}
