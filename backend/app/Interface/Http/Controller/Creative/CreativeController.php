<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Creative;

use App\Application\Creative\Command\AddCreativeVersion;
use App\Application\Creative\Command\AssignCreativeOwner;
use App\Application\Creative\Command\CommentOnRequest;
use App\Application\Creative\Command\DecideOnVersion;
use App\Application\Creative\Command\MoveCreativeRequest;
use App\Application\Creative\Command\OpenCreativeRequest;
use App\Application\Creative\DTO\CommentInput;
use App\Application\Creative\DTO\CreativeRequestInput;
use App\Application\Creative\DTO\VersionInput;
use App\Application\Creative\Query\GetCreativeBoard;
use App\Application\Creative\Query\GetCreativeRequest;
use App\Application\Creative\Query\GetRecordedSpecs;
use App\Application\Creative\Query\ListCreativeOwners;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\DTO\OwnerCandidate;
use App\Domain\Creative\ApprovalOutcome;
use App\Domain\Creative\AssetKind;
use App\Domain\Creative\AssetMeasurements;
use App\Domain\Creative\CommentVisibility;
use App\Domain\Creative\CreativeState;
use App\Domain\Creative\Exception\InvalidCreativeTransition;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Creative\CreativeResource;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creative operations: the board, a request, its versions, their approvals and the comments on them.
 *
 * Every refusal here answers with the **legal alternatives**, not just a message. A person who dragged a card
 * to the wrong column should be able to see where it can go; "invalid transition" makes them ask an engineer.
 */
final class CreativeController extends Controller
{
    /** The board's vocabulary: states, legal moves, asset kinds, comment audiences. */
    public function vocabulary(): JsonResponse
    {
        return response()->json(['data' => CreativeResource::vocabulary()]);
    }

    public function board(Request $request, GetCreativeBoard $board): JsonResponse
    {
        $request->validate(['client_id' => ['nullable', 'uuid'], 'project_id' => ['nullable', 'uuid']]);

        return response()->json([
            'data' => CreativeResource::board($board(
                $this->scopeId($request, 'client_id'),
                $request->integer('per_column') ?: null,
                $this->scopeId($request, 'project_id'),
            )),
        ]);
    }

    public function show(string $creative, GetCreativeRequest $query, Clock $clock): JsonResponse
    {
        return response()->json([
            'data' => CreativeResource::request($query($creative), $clock->now()),
        ]);
    }

    /** Which placements have a recorded specification, and how old each one is. */
    public function specs(GetRecordedSpecs $specs): JsonResponse
    {
        return response()->json(['data' => CreativeResource::specs($specs())]);
    }

    public function store(
        Request $request,
        OpenCreativeRequest $open,
        GetCreativeRequest $query,
        Clock $clock,
    ): JsonResponse {
        $kind = AssetKind::tryFrom($request->string('kind')->value());

        if ($kind === null) {
            return $this->unknown('kind', AssetKind::values());
        }

        $title = trim($request->string('title')->value());

        if ($title === '') {
            return response()->json([
                'message' => 'A brief needs a title. It is what everybody else will call this work.',
                'code' => 'title_required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $view = $open(new CreativeRequestInput(
            clientId: $request->string('client_id')->value(),
            title: $title,
            kind: $kind,
            projectId: $this->scopeId($request, 'project_id'),
            brief: $this->text($request, 'brief'),
            provider: $this->scopeId($request, 'provider'),
            placement: $this->scopeId($request, 'placement'),
            dueAt: $this->date($request, 'due_at'),
            ownerId: $this->scopeId($request, 'owner_id'),
        ));

        return $this->reread($view->id, $query, $clock)->setStatusCode(Response::HTTP_CREATED);
    }

    /** Who this request can be put in the name of. */
    public function owners(string $creative, ListCreativeOwners $list): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (OwnerCandidate $candidate): array => [
                    'id' => $candidate->id,
                    'name' => $candidate->name,
                ],
                $list($creative),
            ),
        ]);
    }

    /**
     * Put a request in somebody's name, or in nobody's.
     *
     * The owner has to be somebody who can open it — a brief carries a client's campaign and often their
     * unreleased plans. `GET /api/creative/{creative}/owners` is the list that cannot be wrong.
     */
    public function assign(
        string $creative,
        Request $request,
        AssignCreativeOwner $assign,
        Clock $clock,
    ): JsonResponse {
        $ownerId = $request->string('owner_id')->value();

        return response()->json([
            'data' => CreativeResource::request(
                $assign($creative, $ownerId === '' ? null : $ownerId),
                $clock->now(),
            ),
        ]);
    }

    public function move(
        string $creative,
        Request $request,
        MoveCreativeRequest $move,
        GetCreativeRequest $query,
        Clock $clock,
    ): JsonResponse {
        $state = CreativeState::tryFrom($request->string('state')->value());

        if ($state === null) {
            return $this->unknown('state', CreativeState::values());
        }

        try {
            $move($creative, $state, $this->text($request, 'reason'));
        } catch (InvalidCreativeTransition $refusal) {
            return $this->refused($refusal);
        }

        return $this->reread($creative, $query, $clock);
    }

    public function addVersion(
        string $creative,
        Request $request,
        AddCreativeVersion $add,
        GetCreativeRequest $query,
        Clock $clock,
    ): JsonResponse {
        $kind = AssetKind::tryFrom($request->string('kind')->value());

        if ($kind === null) {
            return $this->unknown('kind', AssetKind::values());
        }

        $add($creative, new VersionInput(
            kind: $kind,
            /*
             * Taken as given. In a later phase these come from reading the uploaded file; today the caller
             * supplies what it measured, and whatever it left out stays null — which is what makes the spec
             * check report `not checked` instead of a pass.
             */
            measurements: new AssetMeasurements(
                widthPx: $this->integer($request, 'width_px'),
                heightPx: $this->integer($request, 'height_px'),
                fileSizeBytes: $this->integer($request, 'file_size_bytes'),
                durationSeconds: $request->has('duration_seconds') && is_numeric($request->input('duration_seconds'))
                    ? (float) $request->input('duration_seconds')
                    : null,
                format: $this->text($request, 'format'),
                textLength: $this->integer($request, 'text_length'),
                unmeasuredReason: $this->text($request, 'unmeasured_reason'),
            ),
            label: $this->text($request, 'label'),
            storagePath: $this->text($request, 'storage_path'),
            body: $this->text($request, 'body'),
        ));

        return $this->reread($creative, $query, $clock)->setStatusCode(Response::HTTP_CREATED);
    }

    /** Approve a version, or send it back with a reason. */
    public function decide(
        string $version,
        Request $request,
        DecideOnVersion $decide,
        GetCreativeRequest $query,
        Clock $clock,
    ): JsonResponse {
        $outcome = ApprovalOutcome::tryFrom($request->string('outcome')->value());

        if ($outcome === null) {
            return $this->unknown('outcome', ApprovalOutcome::values());
        }

        try {
            $view = $decide($version, $outcome, $this->text($request, 'reason'));
        } catch (InvalidCreativeTransition $refusal) {
            return $this->refused($refusal);
        }

        return $this->reread($view->id, $query, $clock);
    }

    public function comment(
        string $creative,
        Request $request,
        CommentOnRequest $comment,
        GetCreativeRequest $query,
        Clock $clock,
    ): JsonResponse {
        $visibility = CommentVisibility::tryFrom($request->string('visibility')->value());

        if ($visibility === null) {
            /*
             * No default. An agency's internal note on a client's creative reaching that client ends a
             * relationship, and a default is exactly how it would happen.
             */
            return $this->unknown('visibility', CommentVisibility::values());
        }

        $body = trim($request->string('body')->value());

        if ($body === '') {
            return response()->json([
                'message' => 'A comment needs something in it.',
                'code' => 'body_required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $comment($creative, new CommentInput(
            body: $body,
            visibility: $visibility,
            versionId: $this->scopeId($request, 'version_id'),
        ));

        return $this->reread($creative, $query, $clock)->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Re-read a request after writing to it, through the query that composes the specification reports.
     *
     * So a POST answers with exactly the shape a GET does. A write response missing the `spec` block that a
     * read includes is a trap for any client: it would work until somebody relied on the field, and then fail
     * only on the path that created the thing.
     */
    private function reread(string $requestId, GetCreativeRequest $query, Clock $clock): JsonResponse
    {
        return response()->json(['data' => CreativeResource::request($query($requestId), $clock->now())]);
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

    private function refused(InvalidCreativeTransition $refusal): JsonResponse
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

    private function integer(Request $request, string $key): ?int
    {
        return $request->has($key) && is_numeric($request->input($key))
            ? (int) $request->input($key)
            : null;
    }

    private function date(Request $request, string $key): ?DateTimeImmutable
    {
        $value = $request->string($key)->value();

        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    private function scopeId(Request $request, string $key): ?string
    {
        return $request->string($key)->value() ?: null;
    }
}
