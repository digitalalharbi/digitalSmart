<?php

declare(strict_types=1);

namespace App\Application\Shared\Contract;

use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;

/**
 * Write side of the actor context.
 *
 * Exists so the Interface layer can establish who is acting without importing
 * the Infrastructure implementation that stores it.
 */
interface ActorContextBinder
{
    public function bind(UserId $userId, WorkspaceId $workspaceId): void;

    public function isBound(): bool;
}
