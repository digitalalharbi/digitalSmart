<?php

declare(strict_types=1);

namespace App\Application\Attention\Source;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Attention\Contract\AttentionSource;
use App\Application\Attention\DTO\AttentionItem;
use App\Application\Operations\Contract\TaskRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Domain\Attention\AttentionSeverity;
use App\Domain\Attention\AttentionStream;

/**
 * Work that has passed its date.
 *
 * Only overdue tasks, and only once. A board is already a list of what is open; this is the part of it
 * that has stopped being a plan. An unassigned overdue task says so rather than showing a blank where a
 * name would be — it is the one most likely to have been forgotten rather than deferred.
 */
final readonly class TaskAttentionSource implements AttentionSource
{
    private const int CAP = 50;

    public function __construct(
        private TaskRepository $tasks,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    public function stream(): AttentionStream
    {
        return AttentionStream::Tasks;
    }

    /** @return list<AttentionItem> */
    public function items(): array
    {
        $now = $this->clock->now();
        $items = [];

        $overdue = $this->tasks->overdue(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $now,
            self::CAP,
        );

        foreach ($overdue as $task) {
            /* The SQL only narrowed. Whether something is overdue is the view's rule, asked here. */
            if (! $task->isOverdue($now)) {
                continue;
            }

            $items[] = new AttentionItem(
                id: 'task:'.$task->id,
                stream: AttentionStream::Tasks,
                severity: AttentionSeverity::NeedsAttention,
                message: sprintf('Overdue: %s', $task->title),
                entityKind: 'Task',
                entityLabel: $task->clientName ?? $task->projectName ?? $task->title,
                /* Null, not false: a task *is* assignable, and nobody holding it is the finding. */
                owner: $task->assigneeName,
                since: $task->dueOn,
                clientId: $task->clientId,
                clientName: $task->clientName,
                projectId: $task->projectId,
                projectName: $task->projectName,
            );
        }

        return $items;
    }
}
