<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Operations;

use App\Application\Operations\Command\AnswerClientRequest;
use App\Application\Operations\Command\AssignRequestOwner;
use App\Application\Operations\Command\PromiseClientRequest;
use App\Application\Operations\Command\RaiseTaskForRequest;
use App\Application\Operations\Command\SubmitClientRequest;
use App\Application\Operations\Command\TriageClientRequest;
use App\Application\Operations\Contract\ClientRequestFilter;
use App\Application\Operations\DTO\ClientRequestInput;
use App\Application\Operations\Query\GetClientRequest;
use App\Application\Operations\Query\GetIntakeQueue;
use App\Application\Shared\Contract\Clock;
use App\Domain\Operations\ClientRequestKind;
use App\Domain\Operations\ClientRequestState;
use App\Domain\Operations\Exception\InvalidRequestTransition;
use App\Domain\Operations\MessageAudience;
use App\Domain\Operations\StatedUrgency;
use App\Domain\Operations\TaskPriority;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Operations\ClientRequestResource;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client intake queue.
 *
 * The same endpoints serve the agency and a client, and the **read model decides what each sees** from whether
 * the caller holds `client_requests.view`. There is no separate client API, deliberately: two endpoints returning
 * the same record with different visibility rules is two places for the rule to drift, and the one that drifts
 * will be the one a client reads.
 */
final class ClientRequestController extends Controller
{
    public function vocabulary(): JsonResponse
    {
        return response()->json(['data' => ClientRequestResource::vocabulary()]);
    }

    public function queue(Request $request, GetIntakeQueue $queue): JsonResponse
    {
        $kind = $this->kind($request);

        if ($kind === false) {
            return $this->unknown('kind', ClientRequestKind::values());
        }

        $filter = new ClientRequestFilter(
            clientId: $this->scopeId($request, 'client_id'),
            kind: $kind,
            onTheAgencyOnly: $request->boolean('on_the_agency'),
        );

        return response()->json([
            'data' => ClientRequestResource::queue($queue($filter, $request->integer('per_column') ?: null)),
        ]);
    }

    public function show(string $clientRequest, GetClientRequest $query, Clock $clock): JsonResponse
    {
        return response()->json([
            'data' => ClientRequestResource::request($query($clientRequest), $clock->now()),
        ]);
    }

    public function store(Request $request, SubmitClientRequest $submit, Clock $clock): JsonResponse
    {
        $kind = $this->kind($request);

        if ($kind === false || $kind === null) {
            return $this->unknown('kind', ClientRequestKind::values());
        }

        $urgency = StatedUrgency::tryFrom($request->string('stated_urgency')->value());

        if ($request->string('stated_urgency')->value() !== '' && $urgency === null) {
            return $this->unknown('stated_urgency', StatedUrgency::values());
        }

        $clientId = $this->scopeId($request, 'client_id');
        $title = trim($request->string('title')->value());

        if ($clientId === null) {
            return response()->json([
                'message' => 'Say which client this is for. A request with no client is not a client request.',
                'code' => 'client_required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($title === '') {
            return response()->json([
                'message' => 'A request needs a title. It is what the queue will call it.',
                'code' => 'title_required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $view = $submit(new ClientRequestInput(
            clientId: $clientId,
            kind: $kind,
            title: $title,
            detail: $this->text($request, 'detail'),
            projectId: $this->scopeId($request, 'project_id'),
            /*
             * The mildest value unless the client says otherwise. Defaulting to urgent would make every request
             * urgent, after which nothing is — and it would be putting words in a client's mouth.
             */
            urgency: $urgency ?? StatedUrgency::WheneverYouCan,
            wantedBy: $this->date($request, 'wanted_by'),
        ));

        return response()->json(
            ['data' => ClientRequestResource::request($view, $clock->now())],
            Response::HTTP_CREATED,
        );
    }

    public function triage(
        string $clientRequest,
        Request $request,
        TriageClientRequest $triage,
        Clock $clock,
    ): JsonResponse {
        $state = ClientRequestState::tryFrom($request->string('state')->value());

        if ($state === null) {
            return $this->unknown('state', ClientRequestState::values());
        }

        try {
            $view = $triage($clientRequest, $state, $this->text($request, 'reason'));
        } catch (InvalidRequestTransition $refusal) {
            return $this->refused($refusal);
        }

        return response()->json(['data' => ClientRequestResource::request($view, $clock->now())]);
    }

    /** Commit to a date, or withdraw the commitment by sending none. */
    public function promise(
        string $clientRequest,
        Request $request,
        PromiseClientRequest $promise,
        Clock $clock,
    ): JsonResponse {
        return response()->json([
            'data' => ClientRequestResource::request(
                $promise($clientRequest, $this->date($request, 'promised_at')),
                $clock->now(),
            ),
        ]);
    }

    /**
     * Put somebody's name on it, or take it off.
     *
     * An absent `owner_id` removes the owner, which is deliberate rather than a missing field: a queue that can
     * only ever add one accumulates requests owned by whoever opened them first.
     */
    public function assign(
        string $clientRequest,
        Request $request,
        AssignRequestOwner $assign,
        Clock $clock,
    ): JsonResponse {
        return response()->json([
            'data' => ClientRequestResource::request(
                $assign($clientRequest, $this->scopeId($request, 'owner_id')),
                $clock->now(),
            ),
        ]);
    }

    /**
     * Raise a task to answer this request.
     *
     * The request stays a request — this is a link, not a conversion. See `RaiseTaskForRequest` for why, and for
     * why a question is refused.
     */
    public function raiseTask(
        string $clientRequest,
        Request $request,
        RaiseTaskForRequest $raise,
        Clock $clock,
    ): JsonResponse {
        $title = trim($request->string('title')->value());

        if ($title === '') {
            return response()->json([
                'message' => 'The task needs a title. It is what will appear on the board.',
                'code' => 'title_required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $priority = TaskPriority::tryFrom($request->string('priority')->value());

        if ($request->string('priority')->value() !== '' && $priority === null) {
            return $this->unknown('priority', TaskPriority::values());
        }

        try {
            $view = $raise(
                $clientRequest,
                $title,
                /*
                 * The agency's own judgement, defaulted to normal. Deliberately not derived from the client's
                 * stated urgency — see `StatedUrgency`.
                 */
                $priority ?? TaskPriority::Normal,
                $this->scopeId($request, 'assignee_id'),
                $this->date($request, 'due_on'),
                $this->text($request, 'description'),
            );
        } catch (InvalidRequestTransition $refusal) {
            return $this->refused($refusal);
        }

        return response()->json(
            ['data' => ClientRequestResource::request($view, $clock->now())],
            Response::HTTP_CREATED,
        );
    }

    public function answer(
        string $clientRequest,
        Request $request,
        AnswerClientRequest $answer,
        Clock $clock,
    ): JsonResponse {
        $audience = MessageAudience::tryFrom($request->string('audience')->value());

        if ($audience === null) {
            /*
             * No default. An agency's internal note about a client reaching that client ends a relationship, and
             * a default is exactly how it happens. A client's own message has its audience forced by the use
             * case, so this refusal only ever reaches agency staff — who are the people it is protecting.
             */
            return $this->unknown('audience', MessageAudience::values());
        }

        $body = trim($request->string('body')->value());

        if ($body === '') {
            return response()->json([
                'message' => 'A message needs something in it.',
                'code' => 'body_required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(
            ['data' => ClientRequestResource::request($answer($clientRequest, $audience, $body), $clock->now())],
            Response::HTTP_CREATED,
        );
    }

    /** @return ClientRequestKind|null|false false means present and unrecognised */
    private function kind(Request $request): ClientRequestKind|null|false
    {
        $value = $request->string('kind')->value();

        if ($value === '') {
            return null;
        }

        return ClientRequestKind::tryFrom($value) ?? false;
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

    private function refused(InvalidRequestTransition $refusal): JsonResponse
    {
        return response()->json([
            'message' => $refusal->getMessage(),
            'code' => $refusal->reasonCode,
            'from' => $refusal->from->value,
            // Where it can go instead, so somebody fixes their own mistake rather than filing a bug.
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
