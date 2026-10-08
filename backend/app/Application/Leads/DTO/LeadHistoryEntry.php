<?php

declare(strict_types=1);

namespace App\Application\Leads\DTO;

use App\Domain\Leads\LeadStage;
use DateTimeImmutable;

/**
 * One thing that happened to a lead.
 *
 * `actorName` is null for something the system recorded rather than a person — a CRM sync moving a stage, an
 * arrival. That is the honest answer to "who did this" and it is why the column is nullable rather than
 * defaulting to whoever was logged in.
 */
final readonly class LeadHistoryEntry
{
    public function __construct(
        public string $id,
        public string $type,
        public string $typeLabel,
        public ?string $actorName,
        public ?LeadStage $fromStage,
        public ?LeadStage $toStage,
        public ?string $note,
        public DateTimeImmutable $occurredAt,
    ) {}
}
