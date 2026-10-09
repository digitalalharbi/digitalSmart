<?php

declare(strict_types=1);

namespace App\Application\Operations\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\ClientRequestRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\OwnerDirectory;
use App\Application\Shared\DTO\OwnerCandidate;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/**
 * Who this client request can be put in the name of.
 *
 * `ClientRequestsTriage` to read it — the same permission that assigns one — and `ClientRequestsView` to be *in*
 * it. The two differ on purpose: deciding who handles a client's question is a triage decision, and being able
 * to handle it is the agency's own view of the queue, which a client viewer does not hold.
 */
final readonly class ListRequestOwners
{
    public function __construct(
        private ClientRequestRepository $requests,
        private OwnerDirectory $owners,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<OwnerCandidate> */
    public function __invoke(string $requestId): array
    {
        $this->guard->ensure(Permission::ClientRequestsTriage);

        $workspaceId = $this->actor->workspaceId()->value;

        $request = $this->requests->find(
            $workspaceId,
            $this->visibility->visibleProjectIds(),
            $requestId,
            false,
        ) ?? throw ResourceNotFound::of('Client request');

        return $this->owners->peopleWhoCanSee(
            $workspaceId,
            $request->clientId,
            Permission::ClientRequestsView,
        );
    }
}
