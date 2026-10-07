<?php

declare(strict_types=1);

namespace App\Application\Operations\Contract;

use App\Application\Operations\DTO\ClientRequestInput;
use App\Application\Operations\DTO\ClientRequestView;
use App\Domain\Operations\ClientRequestState;
use App\Domain\Operations\IntakeLoad;
use App\Domain\Operations\MessageAudience;
use DateTimeImmutable;

/**
 * Reading and writing client requests.
 *
 * `$clientFacing` threads through every read, and it is the parameter that matters: when true, internal messages
 * are **excluded in SQL** rather than filtered after loading. A reader who may not see an internal note must
 * never have it in the response at all — filtering in PHP means the note was selected, serialised somewhere in
 * a stack trace, and one refactor away from being returned.
 */
interface ClientRequestRepository
{
    public function submit(
        string $workspaceId,
        ClientRequestInput $input,
        ?string $actorId,
        bool $asClient,
    ): ClientRequestView;

    /**
     * Visibility is resolved from projects, exactly as the task board and the approval queue resolve it: a
     * request on a visible project, or on a client the actor has any project with. A client viewer assigned to
     * their own project therefore sees their own requests and nobody else's, with no second rule to keep in step.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    public function find(
        string $workspaceId,
        ?array $visibleProjectIds,
        string $requestId,
        bool $clientFacing,
    ): ?ClientRequestView;

    /**
     * Move a request.
     *
     * `$answeredAt` is stamped on the first answer only, which is what makes "how long did the client wait" a
     * measurement rather than a guess.
     */
    public function move(
        string $workspaceId,
        string $requestId,
        ClientRequestState $state,
        ?string $reason,
        DateTimeImmutable $at,
    ): ClientRequestView;

    /** Record a promise, with the person making it. Both or neither — a CHECK enforces it. */
    public function promise(
        string $workspaceId,
        string $requestId,
        ?DateTimeImmutable $promisedAt,
        ?string $promisedBy,
    ): ClientRequestView;

    public function assignOwner(string $workspaceId, string $requestId, ?string $ownerId): ClientRequestView;

    public function message(
        string $workspaceId,
        string $requestId,
        MessageAudience $audience,
        string $body,
        ?string $authorId,
        bool $isFromClient,
        DateTimeImmutable $at,
    ): ClientRequestView;

    /**
     * The queue, as columns, capped per column.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return array<string, list<ClientRequestView>> keyed by state value, every state present
     */
    public function board(
        string $workspaceId,
        ?array $visibleProjectIds,
        ClientRequestFilter $filter,
        int $limitPerColumn,
        bool $clientFacing,
    ): array;

    /**
     * The figures, over the whole filtered queue.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    public function load(
        string $workspaceId,
        ?array $visibleProjectIds,
        ClientRequestFilter $filter,
        DateTimeImmutable $now,
    ): IntakeLoad;
}
