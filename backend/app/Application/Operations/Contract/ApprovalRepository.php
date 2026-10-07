<?php

declare(strict_types=1);

namespace App\Application\Operations\Contract;

use App\Application\Operations\DTO\ApprovalInput;
use App\Application\Operations\DTO\ApprovalView;
use App\Domain\Operations\ApprovalLoad;
use App\Domain\Operations\ApprovalState;
use App\Domain\Operations\EnactmentState;
use DateTimeImmutable;

/**
 * Reading and writing approval requests.
 *
 * As with the task board, the **lists and the figures come from different methods**: the lists are capped, and
 * `load()` aggregates the whole filtered queue in SQL. Counting the rows that came back would be one query
 * fewer and a wrong number as soon as a queue grew past the cap.
 */
interface ApprovalRepository
{
    public function request(string $workspaceId, ApprovalInput $input, ?string $actorId): ApprovalView;

    /** @param list<string>|null $visibleProjectIds */
    public function find(string $workspaceId, ?array $visibleProjectIds, string $requestId): ?ApprovalView;

    /**
     * Record a decision.
     *
     * `$fingerprint` is written as `decided_fingerprint`: the evidence that whoever decided this was looking at
     * this proposal. The use case has already checked it matches.
     */
    public function decide(
        string $workspaceId,
        string $requestId,
        ApprovalState $state,
        ?string $reason,
        ?string $actorId,
        string $fingerprint,
        DateTimeImmutable $at,
        EnactmentState $enactment,
    ): ApprovalView;

    public function withdraw(string $workspaceId, string $requestId, string $reason): ApprovalView;

    /**
     * Change a pending proposal. Returns the view with its new fingerprint.
     *
     * @param  array<string, mixed>  $proposal
     */
    public function amend(
        string $workspaceId,
        string $requestId,
        string $title,
        string $rationale,
        array $proposal,
    ): ApprovalView;

    /** Record that somebody made the change, or deliberately did not. */
    public function recordEnactment(
        string $workspaceId,
        string $requestId,
        EnactmentState $enactment,
        ?string $note,
        ?string $actorId,
        DateTimeImmutable $at,
    ): ApprovalView;

    /**
     * Requests waiting for a decision, oldest first.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<ApprovalView>
     */
    public function pending(
        string $workspaceId,
        ?array $visibleProjectIds,
        ApprovalFilter $filter,
        int $limit,
    ): array;

    /**
     * Approved changes nobody has carried out, oldest first.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<ApprovalView>
     */
    public function outstanding(
        string $workspaceId,
        ?array $visibleProjectIds,
        ApprovalFilter $filter,
        int $limit,
    ): array;

    /**
     * Recently settled requests, newest first.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<ApprovalView>
     */
    public function settled(
        string $workspaceId,
        ?array $visibleProjectIds,
        ApprovalFilter $filter,
        int $limit,
    ): array;

    /** @param list<string>|null $visibleProjectIds */
    public function load(
        string $workspaceId,
        ?array $visibleProjectIds,
        ApprovalFilter $filter,
        DateTimeImmutable $now,
    ): ApprovalLoad;
}
