<?php

declare(strict_types=1);

namespace App\Application\Leads\DTO;

use App\Domain\Leads\CrmStageMeaning;

/**
 * One stage of a CRM pipeline, as the CRM published it.
 *
 * The **label and the meaning are separate fields on purpose**, and they come from different places. The label is
 * what the people using the CRM called it — "Negotiation", "Mudawalat", "Stage 3" — and this product shows it
 * and reads nothing from it. The meaning comes from the stage's published metadata, which is a structured
 * statement rather than a name. See `CrmStageMeaning`.
 */
final readonly class CrmPipelineStage
{
    public function __construct(
        /** The CRM's internal id. What a deal's stage field actually contains. */
        public string $id,
        /** What a person renamed it to. Shown, never interpreted. */
        public string $label,
        public int $displayOrder,
        public CrmStageMeaning $meaning,
        /** The figures the meaning was derived from, kept so a reader can check the derivation. */
        public ?float $probability,
        public ?bool $isClosed,
    ) {}
}
