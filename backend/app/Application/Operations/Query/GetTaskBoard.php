<?php

declare(strict_types=1);

namespace App\Application\Operations\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\TaskFilter;
use App\Application\Operations\Contract\TaskRepository;
use App\Application\Operations\DTO\TaskBoardView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\Permission;

/**
 * The board, its figures, and who is carrying it.
 *
 * **One moment for the whole board.** The clock is read once and passed to every aggregate, so a card, the
 * overdue count and the due-profile chart all place the same task in the same bucket. Reading the clock per
 * aggregate is how a board comes to report four overdue cards and draw three.
 *
 * **One filter for all three reads.** The cards, the counts and the per-person load are narrowed by the same
 * object, because a figure drawn over a wider population than the cards beside it is the kind of wrong
 * number that reads as right for months.
 */
final readonly class GetTaskBoard
{
    /** Enough per column to see the shape of the work without loading a quarter of it. */
    private const int PER_COLUMN = 50;

    public function __construct(
        private TaskRepository $tasks,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    public function __invoke(TaskFilter $filter, ?int $perColumn = null): TaskBoardView
    {
        $this->guard->ensure(Permission::TasksView);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();
        $cap = max(1, min($perColumn ?? self::PER_COLUMN, 200));
        $now = $this->clock->now();

        return new TaskBoardView(
            columns: $this->tasks->board($workspaceId, $visible, $filter, $cap),
            workload: $this->tasks->workload($workspaceId, $visible, $filter, $now),
            load: $this->tasks->load($workspaceId, $visible, $filter, $now),
            now: $now,
            isTruncated: $this->tasks->exceedsCap($workspaceId, $visible, $filter, $cap),
        );
    }
}
