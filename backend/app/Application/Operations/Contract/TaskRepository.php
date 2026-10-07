<?php

declare(strict_types=1);

namespace App\Application\Operations\Contract;

use App\Application\Operations\DTO\AssigneeLoadView;
use App\Application\Operations\DTO\TaskChange;
use App\Application\Operations\DTO\TaskInput;
use App\Application\Operations\DTO\TaskView;
use App\Domain\Operations\TaskState;
use App\Domain\Operations\TaskSubjectType;
use App\Domain\Operations\TaskWorkload;
use DateTimeImmutable;

/**
 * Reading and writing operational work.
 *
 * The board's **cards and its counts come from different methods on purpose**. `board()` returns a capped
 * page per column; `workload()` and `load()` aggregate the whole board in SQL. Deriving the figures from
 * the cards would be one query fewer and a wrong number the day a client got busy.
 */
interface TaskRepository
{
    public function open(string $workspaceId, TaskInput $input, ?string $actorId): TaskView;

    /** @param list<string>|null $visibleProjectIds null means every project is visible */
    public function find(string $workspaceId, ?array $visibleProjectIds, string $taskId): ?TaskView;

    /**
     * Move a task.
     *
     * `$completedAt` is passed in rather than read from a clock here, so the moment a task was finished
     * comes from the same clock everything else in the request used — and a test can place it.
     */
    public function move(
        string $workspaceId,
        string $taskId,
        TaskState $state,
        ?string $reason,
        DateTimeImmutable $at,
    ): TaskView;

    /** Hand the work to somebody, or to nobody. `$assigneeId` of null unassigns. */
    public function assign(string $workspaceId, string $taskId, ?string $assigneeId): TaskView;

    public function update(string $workspaceId, string $taskId, TaskChange $change): TaskView;

    /**
     * The board, capped per column.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return array<string, list<TaskView>> keyed by state value, every state present
     */
    public function board(
        string $workspaceId,
        ?array $visibleProjectIds,
        TaskFilter $filter,
        int $limitPerColumn,
    ): array;

    /**
     * The tasks raised about one thing, newest first.
     *
     * Its own method rather than a filtered board, because a board is six queries — one per column — and a list
     * of tasks about one subject is one. Reading it through `board()` was measurably slow enough to show up in the
     * test suite, which is the right place to notice that a query shaped for a board was being used as a lookup.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<TaskView>
     */
    public function forSubject(
        string $workspaceId,
        ?array $visibleProjectIds,
        TaskSubjectType $type,
        string $subjectId,
        int $limit,
    ): array;

    /**
     * Whether any column held more rows than the cap.
     *
     * Asked separately so the interface can say the cards on screen are a page of the work rather than all
     * of it. A board that quietly truncated would make its own card counts disagree with its figures.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    public function exceedsCap(
        string $workspaceId,
        ?array $visibleProjectIds,
        TaskFilter $filter,
        int $limitPerColumn,
    ): bool;

    /**
     * The figures, over the whole board rather than over the visible cards.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    public function workload(
        string $workspaceId,
        ?array $visibleProjectIds,
        TaskFilter $filter,
        DateTimeImmutable $today,
    ): TaskWorkload;

    /**
     * Who is carrying the open work. Includes the unassigned pile as a row with a null id.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<AssigneeLoadView>
     */
    public function load(
        string $workspaceId,
        ?array $visibleProjectIds,
        TaskFilter $filter,
        DateTimeImmutable $today,
    ): array;
}
