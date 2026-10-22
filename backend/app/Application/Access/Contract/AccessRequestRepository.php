<?php

declare(strict_types=1);

namespace App\Application\Access\Contract;

use App\Application\Access\DTO\AccessRequestView;
use App\Domain\Access\AccessRequestNeed;
use App\Domain\Access\AccessRequestStatus;
use DateTimeImmutable;

interface AccessRequestRepository
{
    /** The workspace that takes requests, or null when this installation does not take any. */
    public function intakeWorkspaceId(): ?string;

    /** Whether somebody with this address already has a request waiting since `$since`. */
    public function hasPending(string $workspaceId, string $email, DateTimeImmutable $since): bool;

    public function add(
        string $id,
        string $workspaceId,
        string $name,
        string $email,
        ?string $company,
        ?string $phone,
        AccessRequestNeed $need,
        ?string $message,
        string $locale,
        DateTimeImmutable $at,
    ): void;

    /** @return list<AccessRequestView> */
    public function list(string $workspaceId, ?AccessRequestStatus $status, int $limit): array;

    public function find(string $workspaceId, string $id): ?AccessRequestView;

    public function decide(
        string $workspaceId,
        string $id,
        AccessRequestStatus $status,
        string $reviewerId,
        ?string $note,
        DateTimeImmutable $at,
    ): void;

    /** @return array<string, int> requests per status, whole table for the workspace */
    public function counts(string $workspaceId): array;
}
