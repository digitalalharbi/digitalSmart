<?php

declare(strict_types=1);

namespace App\Application\Creative\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Creative\Contract\CreativeRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\OwnerDirectory;
use App\Application\Shared\DTO\OwnerCandidate;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/**
 * Who this creative request can be put in the name of.
 *
 * `CreativeManage` to read it, because the list exists to be acted on; `CreativeView` to be *in* it, because
 * making the work and reading the board are different things. Same directory as the task board, the intake
 * queue and the lead pipeline.
 */
final readonly class ListCreativeOwners
{
    public function __construct(
        private CreativeRepository $requests,
        private OwnerDirectory $owners,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<OwnerCandidate> */
    public function __invoke(string $requestId): array
    {
        $this->guard->ensure(Permission::CreativeManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $request = $this->requests->find($workspaceId, $this->visibility->visibleProjectIds(), $requestId)
            ?? throw ResourceNotFound::of('Creative request');

        return $this->owners->peopleWhoCanSee($workspaceId, $request->clientId, Permission::CreativeView);
    }
}
