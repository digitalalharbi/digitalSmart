<?php

declare(strict_types=1);

namespace App\Application\Identity\Contract;

use App\Application\Identity\DTO\AuthenticatedUser;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;

interface IdentityReadModel
{
    public function authenticatedUser(UserId $userId, ?WorkspaceId $activeWorkspaceId): ?AuthenticatedUser;

    /** The workspace a session should start in: the user's first active membership. */
    public function defaultWorkspaceId(UserId $userId): ?WorkspaceId;

    public function isMemberOf(UserId $userId, WorkspaceId $workspaceId): bool;
}
