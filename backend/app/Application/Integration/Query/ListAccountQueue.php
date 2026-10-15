<?php

declare(strict_types=1);

namespace App\Application\Integration\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Integration\Contract\ConnectionDirectory;
use App\Application\Integration\DTO\AccountRow;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

/**
 * Discovered accounts and where each one stands: unassigned, bound, shared between projects, or ignored.
 *
 * Unassigned data must neither disappear nor be silently bound — the only remaining option is that
 * somebody can find it. Counts are over the whole population, because "eleven need assigning" is what
 * somebody acts on and "eleven on this page" changes when they scroll.
 */
final readonly class ListAccountQueue
{
    public function __construct(
        private ConnectionDirectory $directory,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /**
     * @param  list<string>  $states
     * @return array{accounts: list<AccountRow>, counts: array<string, int>}
     */
    public function __invoke(array $states = [], ?string $connectionId = null, int $limit = 50, int $offset = 0): array
    {
        $this->guard->ensure(Permission::WorkspacesView);

        $workspace = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        return [
            'accounts' => $this->directory->accounts($workspace, $visible, $states, $connectionId, max(1, min($limit, 200)), max(0, $offset)),
            'counts' => $this->directory->accountCounts($workspace, $visible),
        ];
    }
}
