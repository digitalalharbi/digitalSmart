<?php

declare(strict_types=1);

namespace App\Application\Search\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Search\Contract\SearchReadModel;
use App\Application\Search\DTO\SearchHit;
use App\Application\Shared\Contract\ActorContext;
use App\Domain\Access\Permission;
use App\Domain\Access\PermissionSet;

/**
 * One box for the whole agency.
 *
 * One statement for every entity type rather than six requests from the browser: the union happens in the
 * database, scoped to what the reader may see, so a hundred-project agency does not pay a hundred round
 * trips to find a campaign. Each type is offered only to a reader holding the permission that opens it —
 * a hit the reader could not open would be a leak in the shape of a convenience.
 */
final readonly class GlobalSearch
{
    public const array TYPES = ['client', 'project', 'campaign', 'account', 'lead', 'report'];

    public function __construct(
        private SearchReadModel $search,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /**
     * @param  list<string>  $types  Empty means every type this reader may search.
     * @return list<SearchHit>
     */
    public function __invoke(string $term, array $types = [], int $perType = 5): array
    {
        $term = trim($term);

        if ($term === '' || ! $this->actor->isBound()) {
            return [];
        }

        $permissions = $this->actor->permissions();
        $allowed = array_values(array_filter(
            self::TYPES,
            fn (string $type): bool => ($types === [] || in_array($type, $types, true))
                && $this->may($permissions, $type),
        ));

        if ($allowed === []) {
            return [];
        }

        return $this->search->search(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $allowed,
            $term,
            max(1, min($perType, 20)),
        );
    }

    private function may(PermissionSet $permissions, string $type): bool
    {
        return match ($type) {
            'client' => $permissions->has(Permission::ClientsView),
            'project' => $permissions->hasAny(Permission::ProjectsViewAll, Permission::ProjectsViewAssigned),
            'campaign', 'account' => $permissions->has(Permission::AnalyticsView),
            'lead' => $permissions->has(Permission::LeadsView),
            'report' => $permissions->has(Permission::ReportsView),
            default => false,
        };
    }
}
