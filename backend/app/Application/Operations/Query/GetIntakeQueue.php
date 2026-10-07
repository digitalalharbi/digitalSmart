<?php

declare(strict_types=1);

namespace App\Application\Operations\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\ClientRequestFilter;
use App\Application\Operations\Contract\ClientRequestRepository;
use App\Application\Operations\DTO\IntakeQueueView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\Permission;

/**
 * The intake queue.
 *
 * Readable by anybody who may submit, which **includes a client** — and the difference is `clientFacing`, decided
 * from whether the actor holds the agency's own view permission. When they do not, internal messages are excluded
 * in SQL rather than filtered afterwards: an agency's note about a client must never be selected for that reader
 * at all, because a note that was selected has been serialised somewhere and is one refactor from being returned.
 */
final readonly class GetIntakeQueue
{
    private const int PER_COLUMN = 50;

    public function __construct(
        private ClientRequestRepository $requests,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    public function __invoke(ClientRequestFilter $filter, ?int $perColumn = null): IntakeQueueView
    {
        $this->guard->ensure(Permission::ClientRequestsSubmit);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();
        $isAgency = $this->guard->allows(Permission::ClientRequestsView);
        $cap = max(1, min($perColumn ?? self::PER_COLUMN, 200));
        $now = $this->clock->now();

        return new IntakeQueueView(
            columns: $this->requests->board($workspaceId, $visible, $filter, $cap, ! $isAgency),
            load: $this->requests->load($workspaceId, $visible, $filter, $now),
            now: $now,
            /*
             * Published so the interface knows which side it is showing. A client's view of this queue hides
             * internal notes, and a page that did not know it was the client's view would offer an "internal
             * note" control that silently produced a client-visible message.
             */
            isAgencyView: $isAgency,
            canTriage: $this->guard->allows(Permission::ClientRequestsTriage),
        );
    }
}
