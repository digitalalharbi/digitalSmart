<?php

declare(strict_types=1);

namespace App\Infrastructure\Access;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Domain\Access\Permission;

/**
 * Resolves analytics visibility from the actor's permissions and assignments.
 *
 * The same rule the projects surface already enforces, applied to performance
 * data: holding `projects.view_all` means the whole workspace, and not holding it
 * means exactly the projects you were assigned to. Assignment is what grants
 * visibility, and removing someone revokes it — including from every chart.
 */
final readonly class ActorVisibilityResolver implements VisibilityResolver
{
    public function __construct(private ActorContext $actor) {}

    /** @return list<string>|null */
    public function visibleProjectIds(): ?array
    {
        if (! $this->actor->isBound()) {
            /*
             * No active workspace membership. An empty list rather than null:
             * unbound must mean "nothing", and null here would mean "everything".
             */
            return [];
        }

        if ($this->actor->permissions()->has(Permission::ProjectsViewAll)) {
            return null;
        }

        return $this->actor->assignedProjectIds();
    }
}
