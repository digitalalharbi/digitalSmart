<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\ClientRequestRepository;
use App\Application\Operations\Contract\TaskRepository;
use App\Application\Operations\DTO\ClientRequestView;
use App\Application\Operations\DTO\TaskInput;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Operations\ClientRequestKind;
use App\Domain\Operations\Exception\InvalidRequestTransition;
use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskSubjectType;
use DateTimeImmutable;

/**
 * Raise a task to answer a client's request.
 *
 * **Not a conversion.** The request stays a request, with its own state, its own promised date and its own
 * conversation; the task is a separate record linked to it. Turning one into the other would lose the half a
 * client can see — and an intake queue whose rows vanish into a task board is one where nobody can tell a client
 * what happened to what they asked for.
 *
 * Two refusals, and the second is the one worth stating:
 *
 *  - **Nothing is raised for a question.** A question needs an answer, not a card. A queue that spawned a task
 *    for every question fills a board with work whose completion is a sentence in a reply.
 *  - **Nothing is raised against a settled request.** Work to answer something already declined or delivered is
 *    either a mistake or a sign the request should be reopened, and reopening is a decision with its own record.
 *
 * The task's **priority is the agency's**, defaulted to normal and never taken from what the client said. See
 * `StatedUrgency`: a client who could set the board's priorities by typing would set all of them.
 */
final readonly class RaiseTaskForRequest
{
    public function __construct(
        private ClientRequestRepository $requests,
        private TaskRepository $tasks,
        private OpenTask $openTask,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(
        string $requestId,
        string $title,
        TaskPriority $priority = TaskPriority::Normal,
        ?string $assigneeId = null,
        ?DateTimeImmutable $dueOn = null,
        ?string $description = null,
    ): ClientRequestView {
        $this->guard->ensure(Permission::ClientRequestsTriage);
        // And the task board's own permission: raising a task is putting work on somebody else's board.
        $this->guard->ensure(Permission::TasksManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        $request = $this->requests->find($workspaceId, $visible, $requestId, false)
            ?? throw ResourceNotFound::of('Client request');

        if (! $request->state->isOpen()) {
            throw InvalidRequestTransition::settledNeedsNoWork($request->state);
        }

        if ($request->kind === ClientRequestKind::Question) {
            throw InvalidRequestTransition::questionsNeedAnAnswer($request->state);
        }

        ($this->openTask)(new TaskInput(
            title: $title,
            // The agency's judgement, never the client's word.
            priority: $priority,
            description: $description,
            clientId: $request->clientId,
            projectId: $request->projectId,
            assigneeId: $assigneeId,
            dueOn: $dueOn,
            subjectType: TaskSubjectType::ClientRequest,
            subjectId: $request->id,
        ));

        /*
         * The request back, not the task, with the new work on it. The caller is looking at a client's request
         * and wants to see what is now attached to it; returning the task would make the interface fetch the
         * request again to redraw.
         *
         * Only the task list is re-read, not the whole request: opening a task changes nothing about the request
         * row, so a second full read would be a query for data this method already holds.
         */
        return $request->withTasks(
            $this->tasks->forSubject($workspaceId, $visible, TaskSubjectType::ClientRequest, $request->id, 50),
        );
    }
}
