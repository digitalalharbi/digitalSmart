<?php

declare(strict_types=1);

namespace App\Application\SurfaceView\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\SurfaceView\Contract\SurfaceViewRepository;
use App\Application\SurfaceView\DTO\SurfaceViewView;
use App\Domain\SurfaceView\SurfaceKind;

/** The views a person may open on one surface: their own, the workspace's, and their projects'. */
final readonly class ListSurfaceViews
{
    public function __construct(
        private SurfaceViewRepository $views,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<SurfaceViewView> */
    public function __invoke(SurfaceKind $surface): array
    {
        $this->guard->ensure($surface->permission());

        return $this->views->visibleTo(
            $this->actor->workspaceId()->value,
            $this->actor->userId()->value,
            $this->visibility->visibleProjectIds(),
            $surface,
        );
    }
}
