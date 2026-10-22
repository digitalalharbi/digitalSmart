<?php

declare(strict_types=1);

namespace App\Application\Access\Query;

use App\Application\Access\Contract\AccessRequestRepository;
use App\Application\Access\DTO\AccessRequestView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\AccessRequestStatus;
use App\Domain\Access\Permission;

/** The requests waiting for this workspace, newest first, with how many sit in each state. */
final readonly class ListAccessRequests
{
    public function __construct(
        private AccessRequestRepository $requests,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return array{rows: list<AccessRequestView>, counts: array<string, int>} */
    public function __invoke(?AccessRequestStatus $status = null, int $limit = 100): array
    {
        $this->guard->ensure(Permission::UsersInvite);

        $workspaceId = $this->actor->workspaceId()->value;

        return [
            'rows' => $this->requests->list($workspaceId, $status, max(1, min($limit, 200))),
            'counts' => $this->requests->counts($workspaceId),
        ];
    }
}
