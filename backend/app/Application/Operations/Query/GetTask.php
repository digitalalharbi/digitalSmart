<?php

declare(strict_types=1);

namespace App\Application\Operations\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\TaskRepository;
use App\Application\Operations\DTO\TaskView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/** One task, with its subject resolved. */
final readonly class GetTask
{
    public function __construct(
        private TaskRepository $tasks,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(string $taskId): TaskView
    {
        $this->guard->ensure(Permission::TasksView);

        return $this->tasks->find(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $taskId,
        ) ?? throw ResourceNotFound::of('Task');
    }
}
