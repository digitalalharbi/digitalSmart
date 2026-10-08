<?php

declare(strict_types=1);

namespace App\Application\Leads\DTO;

/**
 * One pipeline, with its stages.
 *
 * A CRM has several — a sales pipeline, a renewals pipeline, one somebody made for a campaign — and a deal names
 * which it is in. Reading a stage without its pipeline would be reading an id whose meaning depends on a context
 * that was dropped.
 */
final readonly class CrmPipeline
{
    /** @param list<CrmPipelineStage> $stages */
    public function __construct(
        public string $id,
        public string $label,
        public int $displayOrder,
        public bool $isArchived,
        public array $stages,
    ) {}

    public function stage(string $stageId): ?CrmPipelineStage
    {
        foreach ($this->stages as $stage) {
            if ($stage->id === $stageId) {
                return $stage;
            }
        }

        return null;
    }
}
