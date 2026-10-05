<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Dashboard\Contract\DashboardRepository;
use App\Application\Dashboard\DTO\DashboardView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Dashboard\Exception\NameAlreadyUsed;

/**
 * Copy a dashboard someone can see into one they own.
 *
 * This is how a shared dashboard gets adapted without being changed for everyone
 * else: the copy is private to whoever made it, regardless of the original's
 * visibility. Inheriting the original's audience would silently publish a draft.
 *
 * It needs only *visibility* of the source, not ownership — the point is to take a
 * colleague's dashboard as a starting point, which is the common case.
 */
final readonly class DuplicateDashboard
{
    /** Matches the `max:120` the request enforces on a name someone types. */
    private const int MAX_NAME_LENGTH = 120;

    private const int MAX_COPIES = 50;

    public function __construct(
        private DashboardRepository $dashboards,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(string $id, ?string $name = null): DashboardView
    {
        $this->guard->ensure(Permission::SavedViewsManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $actorId = $this->actor->userId()->value;

        $source = $this->dashboards->find(
            $workspaceId,
            $actorId,
            $this->visibility->visibleProjectIds(),
            $id,
        );

        if ($source === null) {
            throw ResourceNotFound::of('Dashboard');
        }

        return $this->dashboards->duplicate(
            $workspaceId,
            $actorId,
            $id,
            // A distinct name, because the unique index is per owner and per name —
            // and because two identically named dashboards in a picker are useless.
            $name === null
                ? $this->freeCopyName($workspaceId, $actorId, $source->name)
                : $this->assertNameIsFree($workspaceId, $actorId, $name),
        );
    }

    /**
     * "Weekly review (copy)", or "(copy 2)" when that is taken as well.
     *
     * Duplicating twice is ordinary — compare two variants of the same dashboard —
     * and the second copy must not fail on a name the *server* chose. Refusing a
     * name the caller never typed would be inexplicable, so the number climbs until
     * one is free. The cap exists so a pathological case ends in a readable 422
     * rather than a loop.
     */
    private function freeCopyName(string $workspaceId, string $actorId, string $sourceName): string
    {
        for ($attempt = 1; $attempt <= self::MAX_COPIES; $attempt++) {
            $suffix = $attempt === 1 ? ' (copy)' : sprintf(' (copy %d)', $attempt);
            // Kept inside the length the API accepts, so the copy can be saved again
            // without the person first having to shorten a name they never chose.
            $candidate = mb_substr($sourceName, 0, self::MAX_NAME_LENGTH - mb_strlen($suffix)).$suffix;

            if (! $this->dashboards->nameTaken($workspaceId, $actorId, $candidate)) {
                return $candidate;
            }
        }

        throw NameAlreadyUsed::byADashboard($sourceName.' (copy)');
    }

    /**
     * A name the caller supplied, which they can change — so a collision is theirs
     * to resolve and is reported rather than worked around.
     *
     * @throws NameAlreadyUsed
     */
    private function assertNameIsFree(string $workspaceId, string $actorId, string $name): string
    {
        if ($this->dashboards->nameTaken($workspaceId, $actorId, $name)) {
            throw NameAlreadyUsed::byADashboard($name);
        }

        return $name;
    }
}
