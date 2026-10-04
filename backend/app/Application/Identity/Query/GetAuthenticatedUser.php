<?php

declare(strict_types=1);

namespace App\Application\Identity\Query;

use App\Application\Identity\Contract\IdentityReadModel;
use App\Application\Identity\DTO\AuthenticatedUser;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;

final readonly class GetAuthenticatedUser
{
    public function __construct(private IdentityReadModel $identities) {}

    public function __invoke(UserId $userId, ?WorkspaceId $workspaceId): AuthenticatedUser
    {
        return $this->identities->authenticatedUser($userId, $workspaceId)
            ?? throw ResourceNotFound::of('User');
    }
}
