<?php

declare(strict_types=1);

namespace App\Application\Operations\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\ApprovalFilter;
use App\Application\Operations\Contract\ApprovalRepository;
use App\Application\Operations\DTO\ApprovalQueueView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\Permission;

/**
 * The queue: what is waiting for a decision, what is waiting to be done, and what has settled.
 *
 * One clock for all three lists and for the figures, so a request cannot appear in one age bucket on the chart
 * and another on its card.
 */
final readonly class GetApprovalQueue
{
    /** Enough to work from without loading a quarter of the history. */
    private const int LIMIT = 50;

    public function __construct(
        private ApprovalRepository $approvals,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    public function __invoke(ApprovalFilter $filter, ?int $limit = null): ApprovalQueueView
    {
        $this->guard->ensure(Permission::ApprovalsView);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();
        $cap = max(1, min($limit ?? self::LIMIT, 200));
        $now = $this->clock->now();

        return new ApprovalQueueView(
            pending: $this->approvals->pending($workspaceId, $visible, $filter, $cap),
            outstanding: $this->approvals->outstanding($workspaceId, $visible, $filter, $cap),
            settled: $this->approvals->settled($workspaceId, $visible, $filter, $cap),
            load: $this->approvals->load($workspaceId, $visible, $filter, $now),
            now: $now,
        );
    }
}
