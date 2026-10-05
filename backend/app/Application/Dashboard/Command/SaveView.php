<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Dashboard\Contract\SavedViewRepository;
use App\Application\Dashboard\DTO\SavedViewInput;
use App\Application\Dashboard\DTO\SavedViewView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Dashboard\Exception\NameAlreadyUsed;
use App\Domain\Dashboard\Visibility;

/**
 * Create or replace a saved view.
 *
 * Two rules here protect someone other than the caller:
 *
 *  - Only the owner may change a view. A shared view anyone can rewrite is a view
 *    nobody can rely on, and the people using it would have no way to know it had
 *    changed under them.
 *  - Sharing to a project requires being able to see that project — otherwise
 *    "share with the project team" doubles as a way to discover which projects exist.
 */
final readonly class SaveView
{
    public function __construct(
        private SavedViewRepository $views,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(SavedViewInput $input, ?string $id = null): SavedViewView
    {
        $this->guard->ensure(Permission::SavedViewsManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $actorId = $this->actor->userId()->value;

        $this->assertProjectScope($input);

        if ($id === null) {
            $this->assertNameIsFree($workspaceId, $actorId, $input->name, null);

            $view = $this->views->create($workspaceId, $actorId, $input);
        } else {
            if (! $this->views->isOwnedBy($workspaceId, $id, $actorId)) {
                /*
                 * A 404, not a 403, and deliberately: confirming that a view exists
                 * but belongs to someone else tells the caller about a record they
                 * were never shown.
                 */
                throw ResourceNotFound::of('Saved view');
            }

            $this->assertNameIsFree($workspaceId, $actorId, $input->name, $id);

            $view = $this->views->update($workspaceId, $id, $input);
        }

        if ($input->isDefault) {
            $this->views->makeDefault($workspaceId, $actorId, $view->id);
        }

        return $view;
    }

    /**
     * Names are unique per owner, and the index enforces it either way.
     *
     * Asking first turns a reused name into a 422 that says which name to change,
     * rather than a failed insert surfacing as a 500 with the constraint in it.
     *
     * @throws NameAlreadyUsed
     */
    private function assertNameIsFree(string $workspaceId, string $actorId, string $name, ?string $exceptId): void
    {
        if ($this->views->nameTaken($workspaceId, $actorId, $name, $exceptId)) {
            throw NameAlreadyUsed::byASavedView($name);
        }
    }

    private function assertProjectScope(SavedViewInput $input): void
    {
        if ($input->visibility !== Visibility::Project) {
            return;
        }

        if ($input->projectId === null) {
            // The audience would be undefined. The database refuses it too.
            throw ResourceNotFound::of('Project');
        }

        $visible = $this->visibility->visibleProjectIds();

        if ($visible !== null && ! in_array($input->projectId, $visible, true)) {
            // Same 404 as an absent project: which projects exist is not something a
            // restricted actor gets to learn by trying to share with them.
            throw ResourceNotFound::of('Project');
        }
    }
}
