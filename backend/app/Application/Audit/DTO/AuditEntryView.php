<?php

declare(strict_types=1);

namespace App\Application\Audit\DTO;

final readonly class AuditEntryView
{
    /** @param array<string, mixed> $changes */
    public function __construct(
        public string $id,
        public string $action,
        public string $group,
        public bool $isSecurityEvent,
        public ?string $actorId,
        public ?string $actorName,
        public ?string $auditableType,
        public ?string $auditableId,
        public ?string $summary,
        public array $changes,
        public ?string $ipAddress,
        public ?string $requestId,
        public ?string $createdAt,
    ) {}
}
