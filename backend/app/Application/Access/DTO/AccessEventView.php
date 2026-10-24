<?php

declare(strict_types=1);

namespace App\Application\Access\DTO;

use App\Domain\Access\AccessEventType;
use DateTimeImmutable;

final readonly class AccessEventView
{
    /** @param array<string, mixed>|null $meta */
    public function __construct(
        public string $id,
        public AccessEventType $type,
        public ?string $actorName,
        public ?string $body,
        public ?array $meta,
        public DateTimeImmutable $occurredAt,
    ) {}
}
