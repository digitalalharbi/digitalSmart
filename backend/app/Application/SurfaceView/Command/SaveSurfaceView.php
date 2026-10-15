<?php

declare(strict_types=1);

namespace App\Application\SurfaceView\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\SurfaceView\Contract\SurfaceViewRepository;
use App\Application\SurfaceView\DTO\SurfaceViewView;
use App\Domain\Access\Permission;
use App\Domain\Dashboard\Exception\NameAlreadyUsed;
use App\Domain\Dashboard\Visibility;
use App\Domain\SurfaceView\SurfaceKind;

/**
 * Save the filters currently applied to a surface.
 *
 * Sharing is decided here, not by the client: a project-scoped view needs a project the saver can see, so it
 * can never be aimed at a team they are not on. Filters are cut to the surface's allowlist and to scalars —
 * what is stored is a set of choices, never an instruction.
 */
final readonly class SaveSurfaceView
{
    public function __construct(
        private SurfaceViewRepository $views,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @param array<array-key, mixed> $filters */
    public function __invoke(SurfaceKind $surface, string $name, Visibility $visibility, ?string $projectId, array $filters): SurfaceViewView
    {
        $this->guard->ensure($surface->permission());
        $this->guard->ensure(Permission::SavedViewsManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $actorId = $this->actor->userId()->value;

        if ($visibility === Visibility::Project) {
            $visible = $this->visibility->visibleProjectIds();

            if ($projectId === null || ($visible !== null && ! in_array($projectId, $visible, true))) {
                throw ResourceNotFound::of('Project');
            }
        } else {
            $projectId = null;
        }

        if ($this->views->nameTaken($workspaceId, $actorId, $surface, $name)) {
            throw NameAlreadyUsed::byASavedView($name);
        }

        return $this->views->create($workspaceId, $actorId, $surface, $name, $visibility, $projectId, $this->clean($surface, $filters));
    }

    /**
     * @param  array<array-key, mixed>  $filters
     * @return array<string, string|list<string>>
     */
    private function clean(SurfaceKind $surface, array $filters): array
    {
        $out = [];

        foreach ($surface->filters() as $key) {
            $value = $filters[$key] ?? null;

            if (is_string($value) && $value !== '' && mb_strlen($value) <= 120) {
                $out[$key] = $value;
            } elseif (is_array($value)) {
                $list = array_values(array_filter(
                    $value,
                    static fn (mixed $v): bool => is_string($v) && $v !== '' && mb_strlen($v) <= 120,
                ));

                if ($list !== []) {
                    $out[$key] = array_slice($list, 0, 50);
                }
            }
        }

        return $out;
    }
}
