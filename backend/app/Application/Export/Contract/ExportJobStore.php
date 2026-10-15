<?php

declare(strict_types=1);

namespace App\Application\Export\Contract;

use App\Application\Export\DTO\ExportView;
use App\Domain\Export\ExportKind;
use DateTimeImmutable;

interface ExportJobStore
{
    public function create(string $workspaceId, string $userId, ExportKind $kind, string $subjectId, DateTimeImmutable $at): ExportView;

    /** Scoped to the requester: someone else's export is not found, not forbidden. */
    public function find(string $workspaceId, string $userId, string $id): ?ExportView;

    /** The job regardless of who asked — for the worker, which acts for the requester. */
    public function findForWorker(string $id): ?ExportView;

    /** @return list<ExportView> newest first */
    public function mine(string $workspaceId, string $userId, int $limit): array;

    public function markRunning(string $id, DateTimeImmutable $at): void;

    public function markReady(string $id, string $path, string $filename, int $byteSize, DateTimeImmutable $at, DateTimeImmutable $expiresAt): void;

    public function markFailed(string $id, string $message, DateTimeImmutable $at): void;

    /** @return list<array{id: string, path: string}> files whose retention has lapsed */
    public function lapsed(DateTimeImmutable $now, int $limit): array;

    public function clearFile(string $id): void;

    public function path(string $id): ?string;
}
