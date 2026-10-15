<?php

declare(strict_types=1);

namespace App\Application\Attention\Contract;

/** Which projects, and through them which clients, a person is a member of. Membership, not visibility. */
interface ActorMemberships
{
    /** @return list<string> */
    public function projectIds(string $workspaceId, string $userId): array;

    /** @return list<string> */
    public function clientIds(string $workspaceId, string $userId): array;
}
