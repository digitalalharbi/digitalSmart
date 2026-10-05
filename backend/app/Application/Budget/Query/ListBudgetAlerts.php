<?php

declare(strict_types=1);

namespace App\Application\Budget\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Budget\Contract\BudgetRepository;
use App\Application\Budget\DTO\PacingView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Budget\BudgetStatus;

/**
 * The approved budgets that need looking at today.
 *
 * Derived, not stored. An alert table would have to be kept in step with spend that
 * changes on every sync, and a stale alert is worse than none: it trains people to
 * ignore the list. This recomputes from the same figures the pacing screen shows, so
 * the two can never disagree.
 *
 * Budgets whose spend cannot be totalled are *included*, with their reason. "We cannot
 * tell you how this is pacing" is itself something an operator needs to know, and
 * dropping those rows would make the list look reassuringly short.
 */
final readonly class ListBudgetAlerts
{
    public function __construct(
        private BudgetRepository $budgets,
        private GetBudgetPacing $pacing,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<PacingView> */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::BudgetsView);

        $workspaceId = $this->actor->workspaceId()->value;

        $approved = $this->budgets->visibleTo(
            $workspaceId,
            $this->visibility->visibleProjectIds(),
            [BudgetStatus::Approved],
        );

        $alerts = [];

        foreach ($approved as $budget) {
            $pacing = $this->pacing->pacingFor($workspaceId, $budget);

            if ($this->isAlerting($pacing)) {
                $alerts[] = $pacing;
            }
        }

        /*
         * Overspending first, then unknowns, then underspending — the order someone
         * would work through them in. Money leaving too fast cannot wait for a
         * discussion about delivery.
         */
        usort($alerts, static function (PacingView $a, PacingView $b): int {
            $rank = static fn (PacingView $view): int => match (true) {
                $view->pacing === null => 1,
                $view->pacing->status->isOverspend() => 0,
                default => 2,
            };

            return $rank($a) <=> $rank($b);
        });

        return $alerts;
    }

    private function isAlerting(PacingView $view): bool
    {
        if ($view->pacing === null) {
            // Only when the budget *should* be paced and could not be: a draft is not an
            // alert, it is a draft.
            return $view->budget->status->isPaced();
        }

        return $view->pacing->status->needsAttention()
            || $view->pacing->risksOverspend()
            || $view->pacing->risksUnderDelivery();
    }
}
