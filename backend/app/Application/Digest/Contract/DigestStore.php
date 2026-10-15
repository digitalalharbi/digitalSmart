<?php

declare(strict_types=1);

namespace App\Application\Digest\Contract;

use App\Domain\Attention\DigestKind;
use DateTimeImmutable;

interface DigestStore
{
    /** @return list<array{user_id: string, workspace_id: string}> active members, optionally of one workspace */
    public function recipients(?string $workspaceId): array;

    /**
     * Idempotent per person, kind and day.
     *
     * @param  array<string, mixed>  $summary
     * @return string|null the id when this call *created* the digest, null when it only refreshed one — so a
     *                     caller can tell the person once and not again on a re-run
     */
    public function put(string $workspaceId, string $userId, DigestKind $kind, DateTimeImmutable $on, int $total, array $summary): ?string;

    /** @return list<array{id: string, kind: string, period_on: string, total: int, summary: array<string, mixed>, read_at: ?string}> */
    public function latest(string $workspaceId, string $userId, int $limit): array;

    public function markRead(string $workspaceId, string $userId, string $digestId, DateTimeImmutable $at): void;
}
