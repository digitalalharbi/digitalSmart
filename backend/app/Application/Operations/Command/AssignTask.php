<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Notifications\Command\Announce;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Operations\Contract\BoardSignaller;
use App\Application\Operations\Contract\TaskRepository;
use App\Application\Operations\DTO\TaskView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Operations\OperationsBoard;

/**
 * Hand work to somebody, or to nobody.
 *
 * Its own use case and its own audit action rather than a field on an update, because "who was asked to do
 * this" is the entry a person goes looking for and a generic "updated" buries it.
 *
 * Unassigning is a first-class outcome. A board that can only ever add an owner accumulates work assigned to
 * whoever touched it first, and then the workload chart describes a team that does not exist.
 */
final readonly class AssignTask
{
    public function __construct(
        private TaskRepository $tasks,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private Announce $announce,
        private BoardSignaller $boards,
    ) {}

    public function __invoke(string $taskId, ?string $assigneeId): TaskView
    {
        $this->guard->ensure(Permission::TasksManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $current = $this->tasks->find($workspaceId, $this->visibility->visibleProjectIds(), $taskId)
            ?? throw ResourceNotFound::of('Task');

        $assigned = $this->tasks->assign($workspaceId, $taskId, $assigneeId);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::TaskAssigned,
            auditableType: 'task',
            auditableId: $taskId,
            summary: $assigneeId === null
                ? sprintf('Unassigned "%s"', $assigned->title)
                : sprintf('Assigned "%s" to %s', $assigned->title, $assigned->assigneeName ?? 'somebody'),
            changes: ['from' => $current->assigneeId, 'to' => $assigneeId],
        ));

        if ($assigneeId !== null) {
            /*
             * Only the new assignee, and never the actor — `Announce` drops them. Somebody picking up their own
             * work does not need telling they did it, and unassigning tells nobody: there is no new owner, and
             * notifying the person it was taken from would be a complaint rather than a task.
             */
            ($this->announce)(new NotificationDraft(
                kind: NotificationKind::TaskAssigned,
                title: $assigned->title,
                subjectId: $assigned->id,
                body: $assigned->dueOn === null
                    // Said, because an assignment with no date is the common case and the one that drifts.
                    ? 'No due date.'
                    : 'Due '.$assigned->dueOn->format('Y-m-d').'.',
                onlyUserId: $assigneeId,
                aboutProjectId: $assigned->projectId,
                aboutClientId: $assigned->clientId,
            ));
        }

        /* Whoever has the board open learns it moved. A nudge with no rows in it; see `BoardSignaller`. */
        $this->boards->changed(OperationsBoard::Tasks);

        return $assigned;
    }
}
