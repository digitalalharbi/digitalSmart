<?php

declare(strict_types=1);

namespace App\Application\Operations\DTO;

use App\Domain\Operations\DueHorizon;
use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskState;
use App\Domain\Operations\TaskWorkload;
use DateTimeImmutable;

/**
 * The board: columns, the workload it adds up to, and who is carrying it.
 *
 * Two things here are deliberate and worth not undoing.
 *
 * **The columns are capped and the counts are not.** A column shows at most a page of cards, and the
 * figures beside it are computed over the whole board in SQL. A count taken from the cards on screen would
 * silently become "the first fifty" the day a client got busy — the kind of wrong number that reads as
 * right for months.
 *
 * **Every state is a column, even an empty one.** A board whose columns appear and disappear with the work
 * is a board nobody can learn the shape of.
 */
final readonly class TaskBoardView
{
    /**
     * @param  array<string, list<TaskView>>  $columns  keyed by state value; capped per column
     * @param  list<AssigneeLoadView>  $load  who is carrying the open work, unassigned included
     */
    public function __construct(
        public array $columns,
        public TaskWorkload $workload,
        public array $load,
        public DateTimeImmutable $now,
        /** True when a column held more than the cap, so the interface can say the cards are a page. */
        public bool $isTruncated,
    ) {}

    /** @return list<TaskView> */
    public function column(TaskState $state): array
    {
        return $this->columns[$state->value] ?? [];
    }

    /** The count of open work in a due bucket, from the workload rather than from the cards. */
    public function inHorizon(DueHorizon $horizon): int
    {
        return $this->workload->byHorizon[$horizon->value] ?? 0;
    }

    public function inState(TaskState $state): int
    {
        return $this->workload->byState[$state->value] ?? 0;
    }

    /**
     * The open priorities present anywhere in the load, lowest first.
     *
     * Used to decide a chart's stack order and its legend. Taken from the data rather than from the enum so
     * a board with nothing urgent on it does not carry a legend entry for urgency.
     *
     * @return list<TaskPriority>
     */
    public function prioritiesPresent(): array
    {
        $present = [];

        foreach (TaskPriority::ascending() as $priority) {
            foreach ($this->load as $row) {
                if (($row->byPriority[$priority->value] ?? 0) > 0) {
                    $present[] = $priority;

                    break;
                }
            }
        }

        return $present;
    }
}
