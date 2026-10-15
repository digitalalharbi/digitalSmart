<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Operations\Contract\TaskFilter;
use App\Application\Operations\Contract\TaskRepository;
use App\Application\Operations\DTO\AssigneeLoadView;
use App\Application\Operations\DTO\TaskChange;
use App\Application\Operations\DTO\TaskInput;
use App\Application\Operations\DTO\TaskSubjectView;
use App\Application\Operations\DTO\TaskView;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Operations\DueHorizon;
use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskState;
use App\Domain\Operations\TaskSubjectType;
use App\Domain\Operations\TaskWorkload;
use DateTimeImmutable;
use Exception;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Operational work.
 *
 * Three decisions here are the ones to read before changing anything.
 *
 * **The figures are aggregated in SQL, never derived from the cards.** `board()` returns a capped page per
 * column; `workload()` and `load()` each run one grouped query over the whole filtered board. Counting the
 * rows that came back would be one query fewer and a wrong number the day a client got busy — the kind that
 * reads as right for months.
 *
 * **The due buckets are computed in PHP from a date, against one `$today`.** Postgres could do it, but then
 * the rule would exist twice: once in `DueHorizon` and once in a `CASE`, and the second copy would be the
 * one that forgot that closed work is never overdue.
 *
 * **Subject labels are resolved per type in one query each, then matched up.** There is no foreign key
 * behind a task's subject — `TaskSubjectType` explains the trade — so a subject that has been deleted comes
 * back as `exists: false` rather than as a link to nothing.
 */
final readonly class EloquentTaskRepository implements TaskRepository
{
    public function __construct(private IdentifierGenerator $identifiers) {}

    public function open(string $workspaceId, TaskInput $input, ?string $actorId): TaskView
    {
        $id = $this->identifiers->generate();

        DB::table('tasks')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'client_id' => $input->clientId,
            'project_id' => $input->projectId,
            'title' => $input->title,
            'description' => $input->description,
            'state' => TaskState::Todo->value,
            'priority' => $input->priority->value,
            'assignee_id' => $input->assigneeId,
            'created_by' => $actorId,
            'due_on' => $input->dueOn?->format('Y-m-d'),
            'subject_type' => $input->subjectType?->value,
            'subject_id' => $input->subjectId,
            'state_changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->find($workspaceId, null, $id) ?? throw new Exception('The task was not written.');
    }

    public function find(string $workspaceId, ?array $visibleProjectIds, string $taskId): ?TaskView
    {
        $row = $this->scope($workspaceId, $visibleProjectIds)->where('t.id', $taskId)->first();

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $values */
        $values = (array) $row;

        $views = $this->views([$values]);

        return $views[0] ?? null;
    }

    public function move(
        string $workspaceId,
        string $taskId,
        TaskState $state,
        ?string $reason,
        DateTimeImmutable $at,
    ): TaskView {
        $update = [
            'state' => $state->value,
            'state_changed_at' => $at,
            'updated_at' => now(),
            /*
             * Required by a CHECK for done, and refused for everything else. Clearing it on the way out is
             * what makes reopening honest: a task reopened and finished again was completed the second time,
             * and a stale first completion would quietly enter the next turnaround average.
             */
            'completed_at' => $state->isCompleted() ? $at : null,
        ];

        if ($state === TaskState::Blocked) {
            $update['blocked_reason'] = $reason;
        }

        if ($state === TaskState::Cancelled) {
            $update['cancelled_reason'] = $reason;
        }

        if ($state === TaskState::InProgress) {
            /*
             * Stamped on the first move into progress and never again, so "how long did this take" measures
             * from when somebody picked it up rather than from the last time it was unblocked.
             */
            $update['started_at'] = DB::raw('coalesce(started_at, '.$this->quote($at).')');
        }

        DB::table('tasks')
            ->where('workspace_id', $workspaceId)
            ->where('id', $taskId)
            ->update($update);

        return $this->find($workspaceId, null, $taskId)
            ?? throw new Exception('The task disappeared while being moved.');
    }

    public function assign(string $workspaceId, string $taskId, ?string $assigneeId): TaskView
    {
        DB::table('tasks')
            ->where('workspace_id', $workspaceId)
            ->where('id', $taskId)
            ->update(['assignee_id' => $assigneeId, 'updated_at' => now()]);

        return $this->find($workspaceId, null, $taskId)
            ?? throw new Exception('The task disappeared while being assigned.');
    }

    public function update(string $workspaceId, string $taskId, TaskChange $change): TaskView
    {
        $values = [];

        if ($change->changesTitle && $change->title !== null) {
            $values['title'] = $change->title;
        }

        if ($change->changesDescription) {
            $values['description'] = $change->description;
        }

        if ($change->changesPriority && $change->priority !== null) {
            $values['priority'] = $change->priority->value;
        }

        if ($change->changesDueOn) {
            // Null is written, not skipped. Removing a deadline has to be possible — see `TaskChange`.
            $values['due_on'] = $change->dueOn?->format('Y-m-d');
        }

        if ($change->changesProject) {
            $values['project_id'] = $change->projectId;
        }

        if ($values !== []) {
            $values['updated_at'] = now();

            DB::table('tasks')->where('workspace_id', $workspaceId)->where('id', $taskId)->update($values);
        }

        return $this->find($workspaceId, null, $taskId)
            ?? throw new Exception('The task disappeared while being changed.');
    }

    public function board(
        string $workspaceId,
        ?array $visibleProjectIds,
        TaskFilter $filter,
        int $limitPerColumn,
    ): array {
        $columns = [];

        foreach (TaskState::board() as $state) {
            /*
             * One query per column rather than one window function over all of them. Six small indexed
             * queries beat a window function that cannot use the `(workspace, state)` index, and the shape
             * is the one a reader can follow.
             */
            $rows = $this->cards($workspaceId, $visibleProjectIds, $filter)
                ->where('t.state', $state->value)
                /*
                 * Elevated priority first, then the soonest date. Nulls last, deliberately: undated work
                 * should not sit at the top of a column pretending to be the most urgent thing on it.
                 */
                ->orderByRaw(
                    "case t.priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end"
                )
                ->orderByRaw('t.due_on asc nulls last')
                ->orderBy('t.created_at')
                ->limit($limitPerColumn)
                ->get();

            /** @var list<array<string, mixed>> $values */
            $values = [];

            foreach ($rows as $row) {
                $values[] = (array) $row;
            }

            $columns[$state->value] = $this->views($values);
        }

        return $columns;
    }

    /** @return list<TaskView> */
    public function overdue(string $workspaceId, ?array $visibleProjectIds, DateTimeImmutable $today, int $limit): array
    {
        $rows = $this->scope($workspaceId, $visibleProjectIds)
            ->whereIn('t.state', array_map(static fn (TaskState $s): string => $s->value, TaskState::open()))
            ->whereNotNull('t.due_on')
            ->where('t.due_on', '<', $today->format('Y-m-d'))
            ->orderBy('t.due_on')
            ->orderBy('t.id')
            ->limit($limit)
            ->get();

        /** @var list<array<string, mixed>> $values */
        $values = [];

        foreach ($rows as $row) {
            $values[] = (array) $row;
        }

        return $this->views($values);
    }

    public function forSubject(
        string $workspaceId,
        ?array $visibleProjectIds,
        TaskSubjectType $type,
        string $subjectId,
        int $limit,
    ): array {
        $rows = $this->scope($workspaceId, $visibleProjectIds)
            ->where('t.subject_type', $type->value)
            ->where('t.subject_id', $subjectId)
            ->orderByDesc('t.created_at')
            ->limit($limit)
            ->get();

        /** @var list<array<string, mixed>> $values */
        $values = [];

        foreach ($rows as $row) {
            $values[] = (array) $row;
        }

        return $this->views($values);
    }

    public function exceedsCap(
        string $workspaceId,
        ?array $visibleProjectIds,
        TaskFilter $filter,
        int $limitPerColumn,
    ): bool {
        $rows = $this->filtered($workspaceId, $visibleProjectIds, $filter)
            ->groupBy('t.state')
            ->havingRaw('count(*) > ?', [$limitPerColumn])
            ->get(['t.state']);

        return $rows->isNotEmpty();
    }

    public function workload(
        string $workspaceId,
        ?array $visibleProjectIds,
        TaskFilter $filter,
        DateTimeImmutable $today,
    ): TaskWorkload {
        $rows = $this->filtered($workspaceId, $visibleProjectIds, $filter)
            ->groupBy('t.state', 't.priority', 't.due_on', 't.assignee_id')
            ->get(['t.state', 't.priority', 't.due_on', 't.assignee_id', DB::raw('count(*) as tally')]);

        $byState = array_fill_keys(TaskState::values(), 0);
        $byHorizon = array_fill_keys(DueHorizon::values(), 0);
        $total = $open = $done = $cancelled = 0;
        $overdue = $undated = $unassigned = $elevated = $waiting = 0;

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $tally = (int) ($values['tally'] ?? 0);
            $state = TaskState::tryFrom((string) ($values['state'] ?? '')) ?? TaskState::Todo;
            $priority = TaskPriority::tryFrom((string) ($values['priority'] ?? '')) ?? TaskPriority::Normal;
            $horizon = DueHorizon::of($this->date($values['due_on'] ?? null), $today, $state->isOpen());

            $total += $tally;
            $byState[$state->value] += $tally;

            if (! $state->isOpen()) {
                $state->isCompleted() ? $done += $tally : $cancelled += $tally;

                // Closed work is left out of every attention figure and out of the due profile entirely.
                continue;
            }

            $open += $tally;
            $byHorizon[$horizon->value] += $tally;

            if ($horizon === DueHorizon::Overdue) {
                $overdue += $tally;
            }

            if ($horizon === DueHorizon::Undated) {
                $undated += $tally;
            }

            if ($values['assignee_id'] === null) {
                $unassigned += $tally;
            }

            if ($priority->isElevated()) {
                $elevated += $tally;
            }

            if ($state->isWaitingOnSomebodyElse()) {
                $waiting += $tally;
            }
        }

        return new TaskWorkload(
            total: $total,
            open: $open,
            done: $done,
            cancelled: $cancelled,
            overdue: $overdue,
            undated: $undated,
            unassigned: $unassigned,
            elevated: $elevated,
            waiting: $waiting,
            byState: $byState,
            byHorizon: $byHorizon,
        );
    }

    public function load(
        string $workspaceId,
        ?array $visibleProjectIds,
        TaskFilter $filter,
        DateTimeImmutable $today,
    ): array {
        $rows = $this->filtered($workspaceId, $visibleProjectIds, $filter)
            ->leftJoin('users as a', 'a.id', '=', 't.assignee_id')
            ->whereIn('t.state', array_map(static fn (TaskState $s): string => $s->value, TaskState::open()))
            ->groupBy('t.assignee_id', 'a.name', 't.priority', 't.due_on')
            ->get([
                't.assignee_id',
                'a.name as assignee_name',
                't.priority',
                't.due_on',
                DB::raw('count(*) as tally'),
            ]);

        /** @var array<string, array{name: ?string, open: int, overdue: int, byPriority: array<string, int>}> $people */
        $people = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $tally = (int) ($values['tally'] ?? 0);
            $id = $values['assignee_id'] === null ? '' : (string) $values['assignee_id'];
            $priority = TaskPriority::tryFrom((string) ($values['priority'] ?? '')) ?? TaskPriority::Normal;

            $people[$id] ??= [
                'name' => $this->text($values['assignee_name'] ?? null),
                'open' => 0,
                'overdue' => 0,
                'byPriority' => array_fill_keys(TaskPriority::values(), 0),
            ];

            $people[$id]['open'] += $tally;
            $people[$id]['byPriority'][$priority->value] += $tally;

            if (DueHorizon::of($this->date($values['due_on'] ?? null), $today, true) === DueHorizon::Overdue) {
                $people[$id]['overdue'] += $tally;
            }
        }

        $load = [];

        foreach ($people as $id => $person) {
            $load[] = new AssigneeLoadView(
                userId: $id === '' ? null : $id,
                name: $person['name'],
                open: $person['open'],
                overdue: $person['overdue'],
                byPriority: $person['byPriority'],
            );
        }

        /*
         * Heaviest first, with the unassigned pile last regardless of its size. It is not a person's load,
         * and leading a workload chart with it would read as somebody called "Unassigned" being overworked.
         */
        usort($load, static function (AssigneeLoadView $a, AssigneeLoadView $b): int {
            if ($a->isUnassigned() !== $b->isUnassigned()) {
                return $a->isUnassigned() ? 1 : -1;
            }

            return $b->open <=> $a->open ?: strcmp($a->name ?? '', $b->name ?? '');
        });

        return $load;
    }

    /**
     * The filtered, visibility-scoped board. Every read goes through this.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    /**
     * The filtered set, **without** the name joins.
     *
     * For counting and grouping only. `load()` adds its own join on `users` and a second one here would
     * collide on the alias, which is why the joins live in `cards()` rather than being pushed down into
     * this.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    private function filtered(string $workspaceId, ?array $visibleProjectIds, TaskFilter $filter): Builder
    {
        return $this->applyFilters($this->visible($workspaceId, $visibleProjectIds), $filter);
    }

    /**
     * The filtered set with the client, project and assignee names attached.
     *
     * Anything returning cards has to use this. `filtered()` alone selects from `tasks` with no joins, so
     * a card built on it carries three nulls where names belong — which is exactly what the board did
     * until a test finally asserted on one.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    private function cards(string $workspaceId, ?array $visibleProjectIds, TaskFilter $filter): Builder
    {
        return $this->applyFilters($this->scope($workspaceId, $visibleProjectIds), $filter);
    }

    private function applyFilters(Builder $builder, TaskFilter $filter): Builder
    {
        if ($filter->clientId !== null) {
            $builder->where('t.client_id', $filter->clientId);
        }

        if ($filter->projectId !== null) {
            $builder->where('t.project_id', $filter->projectId);
        }

        if ($filter->unassignedOnly) {
            $builder->whereNull('t.assignee_id');
        } elseif ($filter->assigneeId !== null) {
            $builder->where('t.assignee_id', $filter->assigneeId);
        }

        if ($filter->priority !== null) {
            $builder->where('t.priority', $filter->priority->value);
        }

        if ($filter->subjectType !== null) {
            $builder->where('t.subject_type', $filter->subjectType->value);
        }

        if ($filter->subjectId !== null) {
            $builder->where('t.subject_id', $filter->subjectId);
        }

        return $builder;
    }

    /**
     * The same board with the display joins, for the reads that return cards.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    private function scope(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        return $this->visible($workspaceId, $visibleProjectIds)
            ->leftJoin('clients as c', 'c.id', '=', 't.client_id')
            ->leftJoin('projects as p', 'p.id', '=', 't.project_id')
            ->leftJoin('users as a', 'a.id', '=', 't.assignee_id')
            ->select([
                't.*',
                'c.name as client_name',
                'p.name as project_name',
                'a.name as assignee_name',
            ]);
    }

    /**
     * Who may see which tasks.
     *
     * Three cases, and the third is the one worth stating. A task on a visible project is visible. A task on
     * no project but on a client the actor has a project with is visible, which is the same rule the
     * creative board and the stores use. A task with **neither** a project nor a client is the agency's own
     * internal work — a hiring task, a process fix — and is visible to anyone the permission admits, because
     * there is no client relationship to scope it by and withholding it would hide the board from the people
     * who keep it.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    private function visible(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        $builder = DB::table('tasks as t')->where('t.workspace_id', $workspaceId);

        if ($visibleProjectIds === null) {
            return $builder;
        }

        if ($visibleProjectIds === []) {
            /*
             * No project assignments at all. Internal work is still theirs to see — the permission admitted
             * them to the board — but nothing belonging to a client is.
             */
            return $builder->whereNull('t.project_id')->whereNull('t.client_id');
        }

        return $builder->where(function (Builder $scope) use ($workspaceId, $visibleProjectIds): void {
            $scope->whereIn('t.project_id', $visibleProjectIds)
                ->orWhere(function (Builder $byClient) use ($workspaceId, $visibleProjectIds): void {
                    $byClient->whereNull('t.project_id')
                        ->whereNotNull('t.client_id')
                        ->whereIn('t.client_id', function (Builder $sub) use ($workspaceId, $visibleProjectIds): void {
                            $sub->select('client_id')
                                ->from('projects')
                                ->where('workspace_id', $workspaceId)
                                ->whereIn('id', $visibleProjectIds);
                        });
                })
                ->orWhere(function (Builder $internal): void {
                    $internal->whereNull('t.project_id')->whereNull('t.client_id');
                });
        });
    }

    /**
     * Rows to views, with every subject resolved in one query per type.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<TaskView>
     */
    private function views(array $rows): array
    {
        $subjects = $this->resolveSubjects($rows);
        $views = [];

        foreach ($rows as $values) {
            $type = TaskSubjectType::tryFrom((string) ($values['subject_type'] ?? ''));
            $subjectId = $this->text($values['subject_id'] ?? null);
            $subject = null;

            if ($type !== null && $subjectId !== null) {
                $label = $subjects[$type->value][$subjectId] ?? null;

                $subject = new TaskSubjectView(
                    type: $type,
                    id: $subjectId,
                    label: $label,
                    // Present in the lookup at all means the row is there, named or not.
                    exists: array_key_exists($subjectId, $subjects[$type->value] ?? []),
                );
            }

            $views[] = new TaskView(
                id: (string) ($values['id'] ?? ''),
                title: (string) ($values['title'] ?? ''),
                description: $this->text($values['description'] ?? null),
                state: TaskState::tryFrom((string) ($values['state'] ?? '')) ?? TaskState::Todo,
                priority: TaskPriority::tryFrom((string) ($values['priority'] ?? '')) ?? TaskPriority::Normal,
                clientId: $this->text($values['client_id'] ?? null),
                clientName: $this->text($values['client_name'] ?? null),
                projectId: $this->text($values['project_id'] ?? null),
                projectName: $this->text($values['project_name'] ?? null),
                assigneeId: $this->text($values['assignee_id'] ?? null),
                assigneeName: $this->text($values['assignee_name'] ?? null),
                dueOn: $this->date($values['due_on'] ?? null),
                startedAt: $this->date($values['started_at'] ?? null),
                completedAt: $this->date($values['completed_at'] ?? null),
                blockedReason: $this->text($values['blocked_reason'] ?? null),
                cancelledReason: $this->text($values['cancelled_reason'] ?? null),
                stateChangedAt: $this->date($values['state_changed_at'] ?? null),
                subject: $subject,
                createdAt: $this->date($values['created_at'] ?? null) ?? new DateTimeImmutable('@0'),
            );
        }

        return $views;
    }

    /**
     * Subject labels, one query per type present.
     *
     * A type whose row is absent from the result is a subject that has been deleted, and the view says so
     * rather than rendering a link that goes nowhere.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, ?string>>
     */
    private function resolveSubjects(array $rows): array
    {
        /** @var array<string, list<string>> $wanted */
        $wanted = [];

        foreach ($rows as $values) {
            $type = TaskSubjectType::tryFrom((string) ($values['subject_type'] ?? ''));
            $id = $this->text($values['subject_id'] ?? null);

            if ($type !== null && $id !== null) {
                $wanted[$type->value][] = $id;
            }
        }

        $resolved = [];

        foreach ($wanted as $typeValue => $ids) {
            $type = TaskSubjectType::from($typeValue);
            $labels = [];

            $found = DB::table($type->table())
                ->whereIn('id', array_values(array_unique($ids)))
                ->get(['id', $type->labelColumn().' as label']);

            foreach ($found as $row) {
                /** @var array<string, mixed> $values */
                $values = (array) $row;
                // The key being present is what `exists` reads; a null label means present but unnamed.
                $labels[(string) ($values['id'] ?? '')] = $this->text($values['label'] ?? null);
            }

            $resolved[$typeValue] = $labels;
        }

        return $resolved;
    }

    /** A timestamp as a SQL literal, for the one update that has to coalesce rather than overwrite. */
    private function quote(DateTimeImmutable $at): string
    {
        return "'".$at->format('Y-m-d H:i:s')."'";
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (Exception) {
                return null;
            }
        }

        return null;
    }
}
