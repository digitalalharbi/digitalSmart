<?php

declare(strict_types=1);

namespace App\Application\Identity\Command;

use App\Application\Identity\Contract\IdentityReadModel;
use App\Application\Identity\DTO\AuthenticatedUser;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;

/**
 * Change the session's active workspace.
 *
 * Membership is re-checked here rather than trusted from the request: the
 * workspace id arrives from the client, and it is the key every subsequent
 * query is scoped by.
 */
final readonly class SwitchWorkspace
{
    public function __construct(private IdentityReadModel $identities) {}

    public function __invoke(UserId $userId, WorkspaceId $workspaceId): AuthenticatedUser
    {
        if (! $this->identities->isMemberOf($userId, $workspaceId)) {
            throw ResourceNotFound::of('Workspace');
        }

        return $this->identities->authenticatedUser($userId, $workspaceId)
            ?? throw ResourceNotFound::of('Workspace');
    }
}
