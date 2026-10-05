<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Command;

use App\Application\Dashboard\Contract\SavedViewRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

final readonly class DeleteSavedView
{
    public function __construct(
        private SavedViewRepository $views,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    public function __invoke(string $id): void
    {
        $this->guard->ensure(Permission::SavedViewsManage);

        $workspaceId = $this->actor->workspaceId()->value;

        if (! $this->views->isOwnedBy($workspaceId, $id, $this->actor->userId()->value)) {
            // Whether someone else's view exists is not something a caller learns.
            throw ResourceNotFound::of('Saved view');
        }

        $this->views->delete($workspaceId, $id);
    }
}
