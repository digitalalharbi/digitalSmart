<?php

declare(strict_types=1);

namespace App\Application\Shortcuts\Contract;

use DateTimeImmutable;

interface ShortcutStore
{
    /** Whether the thing exists in this workspace. A pin or a recent is never a reference into another tenant. */
    public function belongs(string $workspaceId, string $type, string $id): bool;

    public function pin(string $workspaceId, string $userId, string $type, string $id, DateTimeImmutable $at): void;

    public function unpin(string $workspaceId, string $userId, string $type, string $id): void;

    /** Records a view and keeps only the most recent `$keep` for the person. */
    public function touch(string $workspaceId, string $userId, string $type, string $id, DateTimeImmutable $at, int $keep): void;

    /** @return list<array{type: string, id: string}> newest first */
    public function pinned(string $workspaceId, string $userId): array;

    /** @return list<array{type: string, id: string}> most recent first */
    public function recent(string $workspaceId, string $userId, int $limit): array;
}
