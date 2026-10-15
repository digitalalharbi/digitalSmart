<?php

declare(strict_types=1);

namespace App\Application\SurfaceView\Command;

use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\SurfaceView\Contract\SurfaceViewRepository;
use App\Domain\Access\Permission;

/** Only the author removes a view. A shared view is somebody's, and everybody else's copy would vanish with it. */
final readonly class DeleteSurfaceView
{
    public function __construct(
        private SurfaceViewRepository $views,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    public function __invoke(string $id): void
    {
        $this->guard->ensure(Permission::SavedViewsManage);

        $workspaceId = $this->actor->workspaceId()->value;

        if (! $this->views->isOwnedBy($workspaceId, $id, $this->actor->userId()->value)) {
            throw ResourceNotFound::of('View');
        }

        $this->views->delete($workspaceId, $id);
    }
}
