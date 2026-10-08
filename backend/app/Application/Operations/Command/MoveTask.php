<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\BoardSignaller;
use App\Application\Operations\Contract\TaskRepository;
use App\Application\Operations\DTO\TaskView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Operations\Exception\InvalidTaskTransition;
use App\Domain\Operations\OperationsBoard;
use App\Domain\Operations\TaskState;

/**
 * Move work along the board.
 *
 * The legal moves are the domain's; this adds the two things a state pair cannot decide on its own — that
 * blocking and cancelling must say why, and that the completion time comes from the request's clock rather
 * than from whatever the caller claims.
 *
 * **The reason is never cleared on the way out.** Work that was blocked on a client's legal review was
 * blocked on a client's legal review, and that stays true after somebody unblocks it. The next person
 * asking why a task took three weeks is asking exactly that question.
 */
final readonly class MoveTask
{
    public function __construct(
        private TaskRepository $tasks,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private Clock $clock,
        private BoardSignaller $boards,
    ) {}

    public function __invoke(string $taskId, TaskState $state, ?string $reason = null): TaskView
    {
        $this->guard->ensure(Permission::TasksManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $current = $this->tasks->find($workspaceId, $this->visibility->visibleProjectIds(), $taskId)
            ?? throw ResourceNotFound::of('Task');

        $current->state->assertCanMoveTo($state);

        if ($state->needsReason() && ($reason === null || trim($reason) === '')) {
            throw InvalidTaskTransition::reasonRequired($current->state, $state);
        }

        $moved = $this->tasks->move($workspaceId, $taskId, $state, $reason, $this->clock->now());

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::TaskMoved,
            auditableType: 'task',
            auditableId: $taskId,
            summary: sprintf('Moved "%s" to %s', $moved->title, $state->label()),
            // Both sides, so "who reopened this" is answerable from the record rather than from memory.
            changes: ['from' => $current->state->value, 'to' => $state->value, 'reason' => $reason],
        ));

        /* Whoever has the board open learns it moved. A nudge with no rows in it; see `BoardSignaller`. */
        $this->boards->changed(OperationsBoard::Tasks);

        return $moved;
    }
}
