<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

use DateTimeImmutable;

/** A stored report definition, read back. */
final readonly class SavedReport
{
    public function __construct(
        public string $id,
        public ReportDefinition $definition,
        public ?string $clientName,
        public ?string $createdByName,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
