<?php

declare(strict_types=1);

namespace App\Application\Operations\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\TaskRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\OwnerDirectory;
use App\Application\Shared\DTO\OwnerCandidate;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/**
 * Who this task can be put in the name of.
 *
 * `TasksManage` to read it, because the list exists to be acted on. A task with no client is reachable by
 * anybody holding `tasks.view`, which is the same rule the notification resolver applies to a draft naming
 * neither a project nor a client.
 */
final readonly class ListTaskAssignees
{
    public function __construct(
        private TaskRepository $tasks,
        private OwnerDirectory $owners,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<OwnerCandidate> */
    public function __invoke(string $taskId): array
    {
        $this->guard->ensure(Permission::TasksManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $task = $this->tasks->find($workspaceId, $this->visibility->visibleProjectIds(), $taskId)
            ?? throw ResourceNotFound::of('Task');

        return $this->owners->peopleWhoCanSee($workspaceId, $task->clientId, Permission::TasksView);
    }
}
