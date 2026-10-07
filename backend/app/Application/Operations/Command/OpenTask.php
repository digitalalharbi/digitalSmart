<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\TaskRepository;
use App\Application\Operations\DTO\TaskInput;
use App\Application\Operations\DTO\TaskView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Operations\Exception\InvalidTaskSubject;

/**
 * Put work on the board.
 *
 * Two refusals, both about a link rather than about the work:
 *
 *  - **Half a subject is refused**, not dropped. A task created with a type and no id would render as a
 *    link to nothing, and silently discarding what a caller sent is how a task ends up detached from the
 *    thing it was created for.
 *  - **A task cannot be opened against a project the actor cannot see.** Checked here rather than left to
 *    the read, because otherwise somebody could file work into a project they have no access to and then
 *    not be able to find it — and the board would be carrying a card nobody can open.
 */
final readonly class OpenTask
{
    public function __construct(
        private TaskRepository $tasks,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(TaskInput $input): TaskView
    {
        $this->guard->ensure(Permission::TasksManage);

        if (! $input->hasWholeSubject()) {
            throw InvalidTaskSubject::incomplete();
        }

        $visible = $this->visibility->visibleProjectIds();

        if ($input->projectId !== null && $visible !== null && ! in_array($input->projectId, $visible, true)) {
            throw ResourceNotFound::of('Project');
        }

        $task = $this->tasks->open(
            $this->actor->workspaceId()->value,
            $input,
            $this->actor->userId()->value,
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::TaskOpened,
            auditableType: 'task',
            auditableId: $task->id,
            summary: sprintf('Opened task "%s"', $task->title),
            changes: [
                'priority' => $task->priority->value,
                /*
                 * Recorded as given, null included. A task opened with no date is the common case and the
                 * one worth being able to find later, because it is the one the overdue figure cannot see.
                 */
                'due_on' => $task->dueOn?->format('Y-m-d'),
                'assignee_id' => $task->assigneeId,
                'client_id' => $task->clientId,
                'project_id' => $task->projectId,
                'subject' => $task->subject === null
                    ? null
                    : $task->subject->type->value.':'.$task->subject->id,
            ],
        ));

        return $task;
    }
}
