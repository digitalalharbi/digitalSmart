<?php

declare(strict_types=1);

namespace App\Application\Budget\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Budget\Contract\BudgetRepository;
use App\Application\Budget\DTO\BudgetView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

final readonly class GetBudget
{
    public function __construct(
        private BudgetRepository $budgets,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(string $id): BudgetView
    {
        $this->guard->ensure(Permission::BudgetsView);

        $budget = $this->budgets->find(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $id,
        );

        if ($budget === null) {
            // The same 404 whether it is missing or outside this actor's projects.
            throw ResourceNotFound::of('Budget');
        }

        return $budget;
    }
}
