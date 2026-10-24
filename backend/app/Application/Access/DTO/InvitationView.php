<?php

declare(strict_types=1);

namespace App\Application\Access\DTO;

use DateTimeImmutable;

final readonly class InvitationView
{
    /** @param list<string> $projectIds */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $accessRequestId,
        public string $tokenHint,
        public string $email,
        public string $name,
        public string $roleId,
        public string $roleSlug,
        public array $projectIds,
        public ?string $createdBy,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $acceptedAt,
        public ?DateTimeImmutable $revokedAt,
        public DateTimeImmutable $createdAt,
    ) {}

    public function isUsable(DateTimeImmutable $now): bool
    {
        return $this->acceptedAt === null && $this->revokedAt === null && $this->expiresAt > $now;
    }
}
