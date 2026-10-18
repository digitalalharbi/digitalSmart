<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Notifications\Command\Announce;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Operations\Contract\BoardSignaller;
use App\Application\Operations\Contract\TaskRepository;
use App\Application\Operations\DTO\TaskInput;
use App\Application\Operations\DTO\TaskView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\OwnerDirectory;
use App\Application\Shared\DTO\OwnerCandidate;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Operations\Exception\CannotBeAssigned;
use App\Domain\Operations\Exception\InvalidTaskSubject;
use App\Domain\Operations\OperationsBoard;

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
        private Announce $announce,
        private BoardSignaller $boards,
        private OwnerDirectory $owners,
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

        /*
         * Everything the task names has to be this workspace's, and visible to this reader. Without it an
         * administrator — who sees every project, so the check above lets anything through — could point a task at
         * another workspace's client or campaign and read its name back off their own board.
         */
        $missing = $this->tasks->missingReference($this->actor->workspaceId()->value, $visible, $input);

        if ($missing !== null) {
            throw ResourceNotFound::of($missing);
        }

        if ($input->assigneeId !== null) {
            /*
             * The same check `AssignTask` makes, because opening a task already in somebody's name has the same
             * consequence as putting it there afterwards: a name in a column attached to a person who cannot
             * open the record and whose notification is correctly suppressed. Without this the rule would hold
             * for the board's control and not for the form that creates the work.
             */
            $eligible = $this->owners->peopleWhoCanSee(
                $this->actor->workspaceId()->value,
                $input->clientId,
                Permission::TasksView,
            );

            $isEligible = array_any(
                $eligible,
                static fn (OwnerCandidate $candidate): bool => $candidate->id === $input->assigneeId,
            );

            if (! $isEligible) {
                throw CannotBeAssigned::toThatPerson('');
            }
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

        if ($task->assigneeId !== null) {
            /*
             * Opening a task already in somebody's name is the commonest way work gets assigned, and it is the
             * same event from the recipient's side — so it is announced here as well as in `AssignTask`. Leaving
             * it out would mean the most frequent assignment was the one nobody was told about.
             *
             * `Announce` drops the actor, so somebody filing their own work is told nothing.
             */
            ($this->announce)(new NotificationDraft(
                kind: NotificationKind::TaskAssigned,
                title: $task->title,
                subjectId: $task->id,
                body: $task->dueOn === null
                    ? 'No due date.'
                    : 'Due '.$task->dueOn->format('Y-m-d').'.',
                onlyUserId: $task->assigneeId,
                aboutProjectId: $task->projectId,
                aboutClientId: $task->clientId,
            ));
        }

        /* Whoever has the board open learns it moved. A nudge with no rows in it; see `BoardSignaller`. */
        $this->boards->changed(OperationsBoard::Tasks);

        return $task;
    }
}
