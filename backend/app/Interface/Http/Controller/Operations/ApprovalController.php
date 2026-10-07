<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Operations;

use App\Application\Operations\Command\AmendProposal;
use App\Application\Operations\Command\DecideApproval;
use App\Application\Operations\Command\RecordEnactment;
use App\Application\Operations\Command\RequestApproval;
use App\Application\Operations\Command\WithdrawApproval;
use App\Application\Operations\Contract\ApprovalFilter;
use App\Application\Operations\DTO\ApprovalInput;
use App\Application\Operations\Query\GetApproval;
use App\Application\Operations\Query\GetApprovalQueue;
use App\Application\Shared\Contract\Clock;
use App\Domain\Operations\ApprovalKind;
use App\Domain\Operations\ApprovalState;
use App\Domain\Operations\EnactmentState;
use App\Domain\Operations\Exception\InvalidApprovalRequest;
use App\Domain\Operations\Exception\InvalidApprovalTransition;
use App\Domain\Operations\Exception\InvalidTaskSubject;
use App\Domain\Operations\Exception\ProposalChanged;
use App\Domain\Operations\TaskSubjectType;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Operations\ApprovalResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The approval queue.
 *
 * One response code here is worth knowing about: a decision whose fingerprint does not match the stored
 * proposal answers **409 Conflict**, with the proposal as it stands now. It is not a validation failure — the
 * request was well formed and was correct when its sender read the screen — and answering 422 would send a
 * client down the "fix your input" path for something only a re-read can resolve.
 */
final class ApprovalController extends Controller
{
    public function vocabulary(): JsonResponse
    {
        return response()->json(['data' => ApprovalResource::vocabulary()]);
    }

    public function queue(Request $request, GetApprovalQueue $queue): JsonResponse
    {
        $kind = $this->kind($request);

        if ($kind === false) {
            return $this->unknown('kind', ApprovalKind::values());
        }

        $filter = new ApprovalFilter(
            clientId: $this->scopeId($request, 'client_id'),
            kind: $kind,
            spendOnly: $request->boolean('spend_only'),
        );

        return response()->json([
            'data' => ApprovalResource::queue($queue($filter, $request->integer('limit') ?: null)),
        ]);
    }

    public function show(string $approval, GetApproval $query, Clock $clock): JsonResponse
    {
        return response()->json([
            'data' => ApprovalResource::request($query($approval), $clock->now()),
        ]);
    }

    public function store(Request $request, RequestApproval $ask, Clock $clock): JsonResponse
    {
        $kind = $this->kind($request);

        if ($kind === false || $kind === null) {
            return $this->unknown('kind', ApprovalKind::values());
        }

        $subjectType = $request->string('subject_type')->value() === ''
            ? null
            : TaskSubjectType::tryFrom($request->string('subject_type')->value());

        if ($request->string('subject_type')->value() !== '' && $subjectType === null) {
            return $this->unknown('subject_type', TaskSubjectType::values());
        }

        $title = trim($request->string('title')->value());

        if ($title === '') {
            return response()->json([
                'message' => 'A request needs a title. It is what the queue will call it.',
                'code' => 'title_required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $view = $ask(new ApprovalInput(
                kind: $kind,
                title: $title,
                rationale: trim($request->string('rationale')->value()),
                proposal: $this->proposal($request),
                clientId: $this->scopeId($request, 'client_id'),
                projectId: $this->scopeId($request, 'project_id'),
                subjectType: $subjectType,
                subjectId: $this->scopeId($request, 'subject_id'),
                supersedesId: $this->scopeId($request, 'supersedes_id'),
            ));
        } catch (InvalidApprovalRequest $refusal) {
            return $this->refusedRequest($refusal);
        } catch (InvalidTaskSubject $refusal) {
            return response()->json([
                'message' => $refusal->getMessage(),
                'code' => 'invalid_subject',
                'allowed' => $refusal->allowed,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(
            ['data' => ApprovalResource::request($view, $clock->now())],
            Response::HTTP_CREATED,
        );
    }

    public function decide(
        string $approval,
        Request $request,
        DecideApproval $decide,
        GetApproval $query,
        Clock $clock,
    ): JsonResponse {
        $outcome = ApprovalState::tryFrom($request->string('outcome')->value());

        if ($outcome === null) {
            return $this->unknown('outcome', [
                ApprovalState::Approved->value,
                ApprovalState::Declined->value,
            ]);
        }

        try {
            $view = $decide(
                $approval,
                $outcome,
                /*
                 * The fingerprint the caller's screen displayed. Required: a decision that cannot say which
                 * proposal it read is the race this module is built to refuse.
                 */
                $this->scopeId($request, 'fingerprint'),
                $this->text($request, 'reason'),
            );
        } catch (ProposalChanged $conflict) {
            return $this->conflicted($approval, $conflict, $query, $clock);
        } catch (InvalidApprovalTransition $refusal) {
            return $this->refusedTransition($refusal);
        }

        return response()->json(['data' => ApprovalResource::request($view, $clock->now())]);
    }

    public function withdraw(
        string $approval,
        Request $request,
        WithdrawApproval $withdraw,
        Clock $clock,
    ): JsonResponse {
        try {
            $view = $withdraw($approval, $this->text($request, 'reason'));
        } catch (InvalidApprovalTransition $refusal) {
            return $this->refusedTransition($refusal);
        }

        return response()->json(['data' => ApprovalResource::request($view, $clock->now())]);
    }

    public function amend(
        string $approval,
        Request $request,
        AmendProposal $amend,
        GetApproval $query,
        Clock $clock,
    ): JsonResponse {
        $current = $query($approval);
        $title = trim($request->string('title')->value());

        try {
            $view = $amend(
                $approval,
                $title === '' ? $current->title : $title,
                $request->has('rationale')
                    ? trim($request->string('rationale')->value())
                    : $current->rationale,
                $request->has('proposal') ? $this->proposal($request) : $current->proposal,
            );
        } catch (InvalidApprovalRequest $refusal) {
            return $this->refusedRequest($refusal);
        } catch (InvalidApprovalTransition $refusal) {
            return $this->refusedTransition($refusal);
        }

        return response()->json(['data' => ApprovalResource::request($view, $clock->now())]);
    }

    /** Record that somebody made the approved change, or deliberately did not. */
    public function enact(
        string $approval,
        Request $request,
        RecordEnactment $record,
        Clock $clock,
    ): JsonResponse {
        $enactment = EnactmentState::tryFrom($request->string('enactment')->value());

        if ($enactment !== EnactmentState::Confirmed && $enactment !== EnactmentState::Abandoned) {
            /*
             * Only the two a person can assert. `not_required` and `awaiting_hand` are states the module sets
             * itself, and letting a caller write them would be a way to clear the outstanding queue without
             * anybody doing anything.
             */
            return $this->unknown('enactment', [
                EnactmentState::Confirmed->value,
                EnactmentState::Abandoned->value,
            ]);
        }

        try {
            $view = $record($approval, $enactment, $this->text($request, 'note'));
        } catch (InvalidApprovalTransition $refusal) {
            return $this->refusedTransition($refusal);
        }

        return response()->json(['data' => ApprovalResource::request($view, $clock->now())]);
    }

    /**
     * 409, with the proposal as it stands.
     *
     * Not a 422: the request was well formed, and was correct when its sender read the screen. Answering with a
     * validation code would send a client down the "fix your input" path for something only a re-read resolves.
     */
    private function conflicted(
        string $approval,
        ProposalChanged $conflict,
        GetApproval $query,
        Clock $clock,
    ): JsonResponse {
        return response()->json([
            'message' => $conflict->getMessage(),
            'code' => 'proposal_changed',
            'sent_fingerprint' => $conflict->sentFingerprint,
            'current_fingerprint' => $conflict->currentFingerprint,
            // The proposal as it is now, so the interface can show what changed without a second round trip.
            'data' => ApprovalResource::request($query($approval), $clock->now()),
        ], Response::HTTP_CONFLICT);
    }

    /** @return ApprovalKind|null|false false means present and unrecognised */
    private function kind(Request $request): ApprovalKind|null|false
    {
        $value = $request->string('kind')->value();

        if ($value === '') {
            return null;
        }

        return ApprovalKind::tryFrom($value) ?? false;
    }

    /** @return array<string, mixed> */
    private function proposal(Request $request): array
    {
        $value = $request->input('proposal');

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
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

    private function refusedRequest(InvalidApprovalRequest $refusal): JsonResponse
    {
        return response()->json([
            'message' => $refusal->getMessage(),
            'code' => $refusal->reasonCode,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function refusedTransition(InvalidApprovalTransition $refusal): JsonResponse
    {
        return response()->json([
            'message' => $refusal->getMessage(),
            'code' => $refusal->reasonCode,
            'state' => $refusal->from->value,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function text(Request $request, string $key): ?string
    {
        $value = trim($request->string($key)->value());

        return $value === '' ? null : $value;
    }

    private function scopeId(Request $request, string $key): ?string
    {
        return $request->string($key)->value() ?: null;
    }
}
