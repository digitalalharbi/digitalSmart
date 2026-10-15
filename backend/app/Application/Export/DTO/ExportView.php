<?php

declare(strict_types=1);

namespace App\Application\Export\DTO;

use App\Domain\Export\ExportKind;
use App\Domain\Export\ExportStatus;
use DateTimeImmutable;

final readonly class ExportView
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $userId,
        public ExportKind $kind,
        public string $subjectId,
        public ExportStatus $status,
        public ?string $filename,
        public ?int $byteSize,
        public ?string $error,
        public DateTimeImmutable $requestedAt,
        public ?DateTimeImmutable $finishedAt,
        public ?DateTimeImmutable $expiresAt,
    ) {}

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }
}
