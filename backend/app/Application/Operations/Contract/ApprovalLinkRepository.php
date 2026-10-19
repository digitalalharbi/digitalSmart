<?php

declare(strict_types=1);

namespace App\Application\Operations\Contract;

use DateTimeImmutable;

interface ApprovalLinkRepository
{
    public function create(
        string $workspaceId,
        string $approvalId,
        string $userId,
        ?string $issuedBy,
        string $channel,
        string $tokenHash,
        string $fingerprint,
        DateTimeImmutable $expiresAt,
        string $delivery,
        DateTimeImmutable $now,
    ): string;

    /**
     * The link behind a token hash, whatever its state.
     *
     * @return array{id: string, workspace_id: string, approval_id: string, user_id: string, channel: string, fingerprint: string, expires_at: DateTimeImmutable, used_at: DateTimeImmutable|null}|null
     */
    public function findByHash(string $tokenHash): ?array;

    /**
     * Mark the link used — only if it is unused and unexpired. True when this call claimed it; false when another
     * request got there first or it had lapsed. The single-use guarantee is this one conditional update.
     */
    public function claim(string $id, DateTimeImmutable $now): bool;

    public function recordOutcome(string $id, string $outcome): void;
}
