<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\BoardSignaller;
use App\Application\Operations\Contract\TaskRepository;
use App\Application\Operations\DTO\TaskChange;
use App\Application\Operations\DTO\TaskView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Operations\OperationsBoard;

/**
 * Change a task's title, description, priority, due date or project.
 *
 * Not its state and not its assignee: both have their own use cases, because both are the entries somebody
 * later goes looking for and a generic "updated" buries them.
 *
 * The audit entry records **both sides of the due date**, which is the one field here people argue about.
 * "It was always due Friday" is a claim this record settles.
 */
final readonly class UpdateTask
{
    public function __construct(
        private TaskRepository $tasks,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private BoardSignaller $boards,
    ) {}

    public function __invoke(string $taskId, TaskChange $change): TaskView
    {
        $this->guard->ensure(Permission::TasksManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        $current = $this->tasks->find($workspaceId, $visible, $taskId)
            ?? throw ResourceNotFound::of('Task');

        if ($change->isEmpty()) {
            // Nothing asked for, so nothing written and nothing logged. An audit entry here would be noise.
            return $current;
        }

        if (
            $change->changesProject
            && $change->projectId !== null
            && $visible !== null
            && ! in_array($change->projectId, $visible, true)
        ) {
            throw ResourceNotFound::of('Project');
        }

        $updated = $this->tasks->update($workspaceId, $taskId, $change);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::TaskUpdated,
            auditableType: 'task',
            auditableId: $taskId,
            summary: sprintf('Changed "%s"', $updated->title),
            changes: [
                'from' => [
                    'due_on' => $current->dueOn?->format('Y-m-d'),
                    'priority' => $current->priority->value,
                    'project_id' => $current->projectId,
                ],
                'to' => $change->toAuditChanges(),
            ],
        ));

        /* Whoever has the board open learns it moved. A nudge with no rows in it; see `BoardSignaller`. */
        $this->boards->changed(OperationsBoard::Tasks);

        return $updated;
    }
}
