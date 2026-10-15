<?php

declare(strict_types=1);

namespace App\Application\Activity\DTO;

use DateTimeImmutable;

final readonly class ActivityItem
{
    public function __construct(
        public string $id,
        public string $action,
        public string $sentence,
        public ?string $actorName,
        public string $entityType,
        public string $entityId,
        public ?string $projectId,
        public ?string $clientId,
        public DateTimeImmutable $at,
    ) {}
}
