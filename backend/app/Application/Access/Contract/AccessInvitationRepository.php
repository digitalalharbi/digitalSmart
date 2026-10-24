<?php

declare(strict_types=1);

namespace App\Application\Access\Contract;

use App\Application\Access\DTO\InvitationView;
use DateTimeImmutable;

interface AccessInvitationRepository
{
    /** @param list<string> $projectIds */
    public function create(
        string $id,
        string $workspaceId,
        string $requestId,
        string $tokenHash,
        string $tokenHint,
        string $email,
        string $name,
        string $roleId,
        array $projectIds,
        ?string $createdBy,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): void;

    public function revokeOpen(string $workspaceId, string $requestId, DateTimeImmutable $at): void;

    public function findByTokenHash(string $tokenHash): ?InvitationView;

    public function latestFor(string $workspaceId, string $requestId): ?InvitationView;

    public function markAccepted(string $id, DateTimeImmutable $at): void;

    /**
     * Invitations made in a workspace on or after `$since`, and how many of them were accepted.
     *
     * @return array{made: int, accepted: int}
     */
    public function acceptance(string $workspaceId, DateTimeImmutable $since): array;
}
