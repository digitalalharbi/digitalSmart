<?php

declare(strict_types=1);

namespace App\Application\Access\Contract;

use App\Application\Access\DTO\AccessEventView;
use App\Application\Access\DTO\AccessRequestView;
use App\Application\Access\DTO\AccessSettings;
use App\Application\Access\DTO\NewAccessRequest;
use App\Domain\Access\AccessEventType;
use App\Domain\Access\AccessRequestStatus;
use DateTimeImmutable;

interface AccessRequestRepository
{
    /** The one workspace that takes requests, or null where the installation takes none. */
    public function intakeWorkspaceId(): ?string;

    /**
     * An earlier request from the same address or number inside the window, or null. Used to link, never to drop.
     */
    public function likelyDuplicate(string $workspaceId, string $email, ?string $phoneKey, DateTimeImmutable $since): ?string;

    public function add(NewAccessRequest $new): void;

    public function find(string $workspaceId, string $id): ?AccessRequestView;

    /**
     * @param  list<AccessRequestStatus>|null  $statuses
     * @return list<AccessRequestView>
     */
    public function list(string $workspaceId, ?array $statuses, ?string $ownerId, int $limit): array;

    /** @return array<string, int> */
    public function counts(string $workspaceId): array;

    /** @param array<string, mixed> $fields columns of the request, by name */
    public function update(string $workspaceId, string $id, array $fields, DateTimeImmutable $at): void;

    /** @param array<string, mixed>|null $meta */
    public function addEvent(
        string $id,
        string $workspaceId,
        string $requestId,
        AccessEventType $type,
        ?string $actorId,
        ?string $body,
        ?array $meta,
        DateTimeImmutable $at,
    ): void;

    /** @return list<AccessEventView> oldest first */
    public function events(string $workspaceId, string $requestId): array;

    /** @return list<AccessRequestView> the requests linked to this one in either direction */
    public function linked(string $workspaceId, string $requestId): array;

    public function settings(string $workspaceId): AccessSettings;

    public function saveSettings(string $workspaceId, int $responseMinutes, ?string $defaultOwnerId, DateTimeImmutable $at): void;

    /**
     * Every open request with nobody having tried it, across workspaces, for the alert run.
     *
     * @return list<array{view: AccessRequestView, minutes: int}>
     */
    public function awaitingFirstAttempt(DateTimeImmutable $since): array;

    /**
     * Approved requests whose invitation was made before `$before` and has not been accepted, across workspaces.
     *
     * @return list<AccessRequestView>
     */
    public function invitationsPending(DateTimeImmutable $before): array;

    /** Stamp the first sign-in of a user who came through an invitation. True when it was the first. */
    public function recordFirstLogin(string $userId, DateTimeImmutable $at): bool;

    /**
     * @return list<AccessRequestView> every request created on or after `$since`
     */
    public function createdSince(string $workspaceId, DateTimeImmutable $since, int $limit): array;
}
