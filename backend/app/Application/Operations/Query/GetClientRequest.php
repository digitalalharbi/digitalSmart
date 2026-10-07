<?php

declare(strict_types=1);

namespace App\Application\Operations\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\ClientRequestRepository;
use App\Application\Operations\DTO\ClientRequestView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/** One client request, with as much of the conversation as the reader may see. */
final readonly class GetClientRequest
{
    public function __construct(
        private ClientRequestRepository $requests,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(string $requestId): ClientRequestView
    {
        $this->guard->ensure(Permission::ClientRequestsSubmit);

        return $this->requests->find(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $requestId,
            // Client-facing unless the reader holds the agency's own view permission.
            ! $this->guard->allows(Permission::ClientRequestsView),
        ) ?? throw ResourceNotFound::of('Client request');
    }
}
