<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Dashboard\Contract\SavedViewRepository;
use App\Application\Dashboard\DTO\SavedViewView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

final readonly class ListSavedViews
{
    public function __construct(
        private SavedViewRepository $views,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<SavedViewView> */
    public function __invoke(): array
    {
        // Reading a view needs analytics access; creating or changing one needs more.
        $this->guard->ensure(Permission::AnalyticsView);

        return $this->views->visibleTo(
            $this->actor->workspaceId()->value,
            $this->actor->userId()->value,
            $this->visibility->visibleProjectIds(),
        );
    }
}
