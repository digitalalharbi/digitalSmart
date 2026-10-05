<?php

declare(strict_types=1);

namespace App\Application\Budget\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Budget\Contract\BudgetRepository;
use App\Application\Budget\DTO\BudgetView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Budget\BudgetStatus;

/**
 * Budgets this actor may see.
 *
 * Visibility is the project assignment model again: without `projects.view_all` an
 * actor sees the budgets of the projects they are on, and the client-level budgets of
 * those clients — because a client's quarterly budget is agreed before anybody decides
 * which project will spend it.
 */
final readonly class ListBudgets
{
    public function __construct(
        private BudgetRepository $budgets,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /**
     * @param  list<BudgetStatus>  $statuses
     * @return list<BudgetView>
     */
    public function __invoke(array $statuses = [], ?string $clientId = null): array
    {
        $this->guard->ensure(Permission::BudgetsView);

        return $this->budgets->visibleTo(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $statuses,
            $clientId,
        );
    }
}
