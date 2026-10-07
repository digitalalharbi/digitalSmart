<?php

declare(strict_types=1);

namespace App\Application\Operations\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\ApprovalRepository;
use App\Application\Operations\DTO\ApprovalView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/** One request, with the fingerprint a decision has to name. */
final readonly class GetApproval
{
    public function __construct(
        private ApprovalRepository $approvals,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(string $requestId): ApprovalView
    {
        $this->guard->ensure(Permission::ApprovalsView);

        return $this->approvals->find(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $requestId,
        ) ?? throw ResourceNotFound::of('Approval request');
    }
}
