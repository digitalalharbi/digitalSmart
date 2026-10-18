<?php

declare(strict_types=1);

namespace App\Application\Creative\Contract;

use App\Application\Creative\DTO\CommentInput;
use App\Application\Creative\DTO\CreativeRequestInput;
use App\Application\Creative\DTO\CreativeRequestView;
use App\Application\Creative\DTO\VersionInput;
use App\Domain\Creative\ApprovalOutcome;
use App\Domain\Creative\CreativeState;

interface CreativeRepository
{
    public function open(string $workspaceId, CreativeRequestInput $input, ?string $actorId): CreativeRequestView;

    /** @param list<string>|null $visibleProjectIds */
    public function find(string $workspaceId, ?array $visibleProjectIds, string $requestId): ?CreativeRequestView;

    /**
     * Put a request in somebody's name, or in nobody's.
     *
     * Its own method rather than a field on a move, because "who is making this" and "where has it got to" are
     * different questions and the trail for each is read by different people.
     */
    public function assignOwner(string $workspaceId, string $requestId, ?string $ownerId): CreativeRequestView;

    public function move(
        string $workspaceId,
        string $requestId,
        CreativeState $state,
        ?string $cancelledReason,
    ): CreativeRequestView;

    /**
     * Add a version, numbered one higher than the last.
     *
     * The repository assigns the number rather than the caller, because two people uploading at once must
     * not produce two version threes — and a unique constraint on (request, number) turns that race into a
     * failed write rather than a silently reordered trail.
     */
    public function addVersion(
        string $workspaceId,
        string $requestId,
        VersionInput $input,
        ?string $actorId,
    ): CreativeRequestView;

    /** Record a decision **about a version**. See the migration's note on why the key points there. */
    public function decide(
        string $workspaceId,
        string $versionId,
        ApprovalOutcome $outcome,
        ?string $reason,
        ?string $actorId,
    ): CreativeRequestView;

    public function comment(
        string $workspaceId,
        string $requestId,
        CommentInput $input,
        ?string $actorId,
    ): CreativeRequestView;

    /**
     * The board.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return array<string, list<CreativeRequestView>> keyed by state value
     */
    public function board(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId,
        int $limitPerColumn,
        ?string $projectId = null,
    ): array;

    /** The version's request id, for a decision that has to check what it is deciding on. */
    public function requestIdForVersion(string $workspaceId, string $versionId): ?string;
}
