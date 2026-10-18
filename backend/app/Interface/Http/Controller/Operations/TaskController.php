<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Operations;

use App\Application\Operations\Command\AssignTask;
use App\Application\Operations\Command\MoveTask;
use App\Application\Operations\Command\OpenTask;
use App\Application\Operations\Command\UpdateTask;
use App\Application\Operations\Contract\TaskFilter;
use App\Application\Operations\DTO\TaskChange;
use App\Application\Operations\DTO\TaskInput;
use App\Application\Operations\Query\GetTask;
use App\Application\Operations\Query\GetTaskBoard;
use App\Application\Operations\Query\ListTaskAssignees;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\DTO\OwnerCandidate;
use App\Domain\Operations\Exception\InvalidTaskSubject;
use App\Domain\Operations\Exception\InvalidTaskTransition;
use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskState;
use App\Domain\Operations\TaskSubjectType;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Operations\TaskResource;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The operations board: the work on it, where it can go, and who is carrying it.
 *
 * Every refusal answers with the **legal alternatives**, not just a message, so somebody who tried an
 * impossible move can fix their own mistake rather than asking an engineer what "invalid transition" means.
 */
final class TaskController extends Controller
{
    /** States, legal moves, the priority scale and the due buckets. */
    public function vocabulary(): JsonResponse
    {
        return response()->json(['data' => TaskResource::vocabulary()]);
    }

    public function board(Request $request, GetTaskBoard $board, ActorContext $actor): JsonResponse
    {
        $priority = $this->enumOrNull($request, 'priority', TaskPriority::class);

        if ($priority === false) {
            return $this->unknown('priority', TaskPriority::values());
        }

        $subjectType = $this->enumOrNull($request, 'subject_type', TaskSubjectType::class);

        if ($subjectType === false) {
            return $this->unknown('subject_type', TaskSubjectType::values());
        }

        /*
         * `assignee=me` is resolved here rather than in the browser. A front end that sent its own idea of
         * who the actor is would be one session-restore bug away from showing somebody else's week.
         */
        $assignee = $this->scopeId($request, 'assignee_id');
        $unassignedOnly = $assignee === 'unassigned';

        if ($assignee === 'me') {
            $assignee = $actor->userId()->value;
        }

        $filter = new TaskFilter(
            clientId: $this->scopeId($request, 'client_id'),
            projectId: $this->scopeId($request, 'project_id'),
            assigneeId: $unassignedOnly ? null : $assignee,
            unassignedOnly: $unassignedOnly,
            priority: $priority,
            subjectType: $subjectType,
            subjectId: $this->scopeId($request, 'subject_id'),
        );

        return response()->json([
            'data' => TaskResource::board($board($filter, $request->integer('per_column') ?: null)),
        ]);
    }

    public function show(string $task, GetTask $query, Clock $clock): JsonResponse
    {
        return response()->json(['data' => TaskResource::task($query($task), $clock->now())]);
    }

    public function store(Request $request, OpenTask $open, Clock $clock): JsonResponse
    {
        /* An id that is not an id is a 422 here rather than a database error three layers down. */
        $request->validate([
            'client_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'subject_id' => ['nullable', 'uuid'],
            'assignee_id' => ['nullable', 'uuid'],
        ]);

        $title = trim($request->string('title')->value());

        if ($title === '') {
            return response()->json([
                'message' => 'A task needs a title. It is what everybody else will call this work.',
                'code' => 'title_required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $priority = $this->enumOrNull($request, 'priority', TaskPriority::class);

        if ($priority === false) {
            return $this->unknown('priority', TaskPriority::values());
        }

        $subjectType = $this->enumOrNull($request, 'subject_type', TaskSubjectType::class);

        if ($subjectType === false) {
            return $this->unknown('subject_type', TaskSubjectType::values());
        }

        try {
            $task = $open(new TaskInput(
                title: $title,
                // Normal unless somebody says otherwise. A default of urgent is how nothing stays urgent.
                priority: $priority ?? TaskPriority::Normal,
                description: $this->text($request, 'description'),
                clientId: $this->scopeId($request, 'client_id'),
                projectId: $this->scopeId($request, 'project_id'),
                assigneeId: $this->scopeId($request, 'assignee_id'),
                dueOn: $this->date($request, 'due_on'),
                subjectType: $subjectType,
                subjectId: $this->scopeId($request, 'subject_id'),
            ));
        } catch (InvalidTaskSubject $refusal) {
            return response()->json([
                'message' => $refusal->getMessage(),
                'code' => 'invalid_subject',
                'allowed' => $refusal->allowed,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => TaskResource::task($task, $clock->now())], Response::HTTP_CREATED);
    }

    public function move(string $task, Request $request, MoveTask $move, Clock $clock): JsonResponse
    {
        $state = TaskState::tryFrom($request->string('state')->value());

        if ($state === null) {
            return $this->unknown('state', TaskState::values());
        }

        try {
            $moved = $move($task, $state, $this->text($request, 'reason'));
        } catch (InvalidTaskTransition $refusal) {
            return $this->refused($refusal);
        }

        return response()->json(['data' => TaskResource::task($moved, $clock->now())]);
    }

    /**
     * Hand the work to somebody, or to nobody.
     *
     * An absent `assignee_id` unassigns, which is a deliberate outcome rather than a missing field: a board
     * that can only ever add an owner accumulates work assigned to whoever touched it first.
     */
    /**
     * Who this task can be put in the name of.
     *
     * Served rather than assembled from the member list, which needs `users.view` — a permission somebody
     * working a board has no reason to hold — and would offer colleagues the assignment then refuses.
     */
    public function assignees(string $task, ListTaskAssignees $list): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (OwnerCandidate $candidate): array => [
                    'id' => $candidate->id,
                    'name' => $candidate->name,
                ],
                $list($task),
            ),
        ]);
    }

    public function assign(string $task, Request $request, AssignTask $assign, Clock $clock): JsonResponse
    {
        return response()->json([
            'data' => TaskResource::task(
                $assign($task, $this->scopeId($request, 'assignee_id')),
                $clock->now(),
            ),
        ]);
    }

    public function update(string $task, Request $request, UpdateTask $update, Clock $clock): JsonResponse
    {
        $change = TaskChange::nothing();

        if ($request->has('title')) {
            $title = trim($request->string('title')->value());

            if ($title === '') {
                return response()->json([
                    'message' => 'A task needs a title.',
                    'code' => 'title_required',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $change = $change->withTitle($title);
        }

        if ($request->has('description')) {
            $change = $change->withDescription($this->text($request, 'description'));
        }

        if ($request->has('priority')) {
            $priority = TaskPriority::tryFrom($request->string('priority')->value());

            if ($priority === null) {
                return $this->unknown('priority', TaskPriority::values());
            }

            $change = $change->withPriority($priority);
        }

        if ($request->has('due_on')) {
            /*
             * Present and empty clears the date. The alternative — treating an empty value as "leave it" —
             * makes a deadline something that can be set but never removed.
             */
            $change = $change->withDueOn($this->date($request, 'due_on'));
        }

        if ($request->has('project_id')) {
            $change = $change->withProject($this->scopeId($request, 'project_id'));
        }

        return response()->json(['data' => TaskResource::task($update($task, $change), $clock->now())]);
    }

    /**
     * An enum from the request, or null when absent, or `false` when present and unrecognised.
     *
     * The three-way return is the point: a filter nobody sent and a filter somebody mistyped are different
     * situations, and silently treating the second as the first would answer a narrowed question with the
     * whole board.
     *
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null|false
     */
    private function enumOrNull(Request $request, string $key, string $enum): mixed
    {
        $value = $request->string($key)->value();

        if ($value === '') {
            return null;
        }

        return $enum::tryFrom($value) ?? false;
    }

    /** @param list<string> $allowed */
    private function unknown(string $field, array $allowed): JsonResponse
    {
        return response()->json([
            'message' => sprintf('Unknown %s.', str_replace('_', ' ', $field)),
            'code' => 'invalid_'.$field,
            'allowed' => $allowed,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function refused(InvalidTaskTransition $refusal): JsonResponse
    {
        return response()->json([
            'message' => $refusal->getMessage(),
            'code' => 'invalid_transition',
            'from' => $refusal->from->value,
            'to' => $refusal->to?->value,
            // Where it *can* go, so somebody fixes their own mistake rather than filing a bug.
            'allowed' => $refusal->allowed,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function text(Request $request, string $key): ?string
    {
        $value = trim($request->string($key)->value());

        return $value === '' ? null : $value;
    }

    private function date(Request $request, string $key): ?DateTimeImmutable
    {
        $value = trim($request->string($key)->value());

        if ($value === '') {
            return null;
        }

        try {
            // Midnight, so a date that arrives with a time on it cannot become a deadline nobody agreed.
            return (new DateTimeImmutable($value))->setTime(0, 0);
        } catch (Exception) {
            return null;
        }
    }

    private function scopeId(Request $request, string $key): ?string
    {
        return $request->string($key)->value() ?: null;
    }
}
