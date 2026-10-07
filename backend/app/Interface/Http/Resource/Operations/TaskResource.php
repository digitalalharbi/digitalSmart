<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Operations;

use App\Application\Operations\DTO\AssigneeLoadView;
use App\Application\Operations\DTO\TaskBoardView;
use App\Application\Operations\DTO\TaskView;
use App\Domain\Operations\DueHorizon;
use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskState;
use App\Domain\Operations\TaskSubjectType;
use DateTimeImmutable;

final readonly class TaskResource
{
    /**
     * The board's own vocabulary, so no client hard-codes the columns, the legal moves or the scale.
     *
     * The same rule the creative board follows: a front end carrying its own copy of the transitions would
     * eventually offer a move the server refuses, and the person moving the card would see an error where a
     * disabled target belonged.
     *
     * @return array<string, mixed>
     */
    public static function vocabulary(): array
    {
        return [
            'states' => array_map(static fn (TaskState $state): array => [
                'state' => $state->value,
                'label' => $state->label(),
                'position' => $state->boardPosition(),
                'is_open' => $state->isOpen(),
                'is_waiting' => $state->isWaitingOnSomebodyElse(),
                'needs_reason' => $state->needsReason(),
                'allowed_next' => array_map(
                    static fn (TaskState $next): string => $next->value,
                    $state->allowedNext(),
                ),
            ], TaskState::board()),
            'priorities' => array_map(static fn (TaskPriority $priority): array => [
                'priority' => $priority->value,
                'label' => $priority->label(),
                /* The rank is published because the scale is ordered, and an ordered scale is drawn in order. */
                'rank' => $priority->rank(),
                'is_elevated' => $priority->isElevated(),
            ], TaskPriority::ascending()),
            'horizons' => array_map(static fn (DueHorizon $horizon): array => [
                'horizon' => $horizon->value,
                'label' => $horizon->label(),
                'rank' => $horizon->rank(),
                'needs_attention' => $horizon->needsAttention(),
            ], DueHorizon::ordered()),
            'subject_types' => array_map(static fn (TaskSubjectType $type): array => [
                'type' => $type->value,
                'label' => $type->label(),
            ], TaskSubjectType::cases()),
        ];
    }

    /** @return array<string, mixed> */
    public static function board(TaskBoardView $board): array
    {
        $workload = $board->workload;

        return [
            'columns' => array_map(static fn (TaskState $state): array => [
                'state' => $state->value,
                'label' => $state->label(),
                /*
                 * Two counts per column, and they are different things. `tasks` is the page on screen;
                 * `total` is how many there are. A board that published one number would be publishing
                 * "the first fifty" the day a client got busy.
                 */
                'total' => $board->inState($state),
                'tasks' => array_map(
                    static fn (TaskView $task): array => self::card($task, $board->now),
                    $board->column($state),
                ),
            ], TaskState::board()),
            'workload' => [
                'total' => $workload->total,
                'open' => $workload->open,
                'done' => $workload->done,
                'cancelled' => $workload->cancelled,
                'overdue' => $workload->overdue,
                /* Published beside overdue rather than folded into it. See `DueHorizon`. */
                'undated' => $workload->undated,
                'unassigned' => $workload->unassigned,
                'elevated' => $workload->elevated,
                'waiting' => $workload->waiting,
                'by_state' => $workload->byState,
                'by_horizon' => $workload->byHorizon,
                /* Null, never zero, when nothing has settled. */
                'completion_rate' => $workload->completionRate(),
                'dated_share' => $workload->datedShare(),
                /*
                 * Whether the overdue figure may be read as the amount of late work. False once much of the
                 * board carries no date, and the interface says so beside the figure rather than leaving a
                 * reader to assume the rest is on time.
                 */
                'is_lateness_reportable' => $workload->isLatenessReportable(),
                'dated_share_floor' => $workload::DATED_SHARE_FLOOR,
            ],
            'load' => array_map(static fn (AssigneeLoadView $row): array => [
                'user_id' => $row->userId,
                'name' => $row->name,
                'is_unassigned' => $row->isUnassigned(),
                'open' => $row->open,
                'overdue' => $row->overdue,
                'by_priority' => $row->byPriority,
            ], $board->load),
            'priorities_present' => array_map(
                static fn (TaskPriority $priority): string => $priority->value,
                $board->prioritiesPresent(),
            ),
            'is_truncated' => $board->isTruncated,
            'as_of' => $board->now->format(DateTimeImmutable::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function task(TaskView $task, DateTimeImmutable $now): array
    {
        return self::card($task, $now) + [
            'description' => $task->description,
            'started_at' => $task->startedAt?->format(DateTimeImmutable::ATOM),
            'completed_at' => $task->completedAt?->format(DateTimeImmutable::ATOM),
            'state_changed_at' => $task->stateChangedAt?->format(DateTimeImmutable::ATOM),
            'created_at' => $task->createdAt->format(DateTimeImmutable::ATOM),
            /* Null while unfinished, and null when nobody recorded a start — unknown, not zero. */
            'working_days' => $task->workingDays(),
        ];
    }

    /** @return array<string, mixed> */
    private static function card(TaskView $task, DateTimeImmutable $now): array
    {
        $horizon = $task->horizon($now);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'state' => $task->state->value,
            'state_label' => $task->state->label(),
            'priority' => $task->priority->value,
            'priority_label' => $task->priority->label(),
            'client_id' => $task->clientId,
            'client_name' => $task->clientName,
            'project_id' => $task->projectId,
            'project_name' => $task->projectName,
            'assignee_id' => $task->assigneeId,
            'assignee_name' => $task->assigneeName,
            /* A date, with no time in it, because that is what was agreed. */
            'due_on' => $task->dueOn?->format('Y-m-d'),
            'horizon' => $horizon->value,
            'horizon_label' => $horizon->label(),
            'is_overdue' => $horizon === DueHorizon::Overdue,
            /* Null with no date: "undated" and "due a long time from now" are different facts. */
            'days_until_due' => $task->daysUntilDue($now),
            'blocked_reason' => $task->blockedReason,
            'cancelled_reason' => $task->cancelledReason,
            'subject' => $task->subject === null ? null : [
                'type' => $task->subject->type->value,
                'type_label' => $task->subject->type->label(),
                'id' => $task->subject->id,
                'label' => $task->subject->label,
                /*
                 * Whether the thing still exists. There is no foreign key behind a subject, so a deleted
                 * one is reported as deleted rather than rendered as a link that goes nowhere.
                 */
                'exists' => $task->subject->exists,
                'path' => $task->subject->path(),
            ],
        ];
    }
}
