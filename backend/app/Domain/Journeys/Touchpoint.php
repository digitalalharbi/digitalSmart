<?php

declare(strict_types=1);

namespace App\Domain\Journeys;

use App\Domain\Commerce\AttributionEvidence;
use DateTimeImmutable;

/** One thing a person did, with what that record itself carried about where it came from. */
final readonly class Touchpoint
{
    public function __construct(
        public TouchpointKind $kind,
        public string $id,
        public DateTimeImmutable $occurredAt,
        public string $summary,
        /** What this record carried. Never inherited from another touchpoint. */
        public AttributionEvidence $attributionEvidence = AttributionEvidence::None,
        public ?string $campaign = null,
        public ?string $source = null,
        public ?int $valueMinor = null,
        public ?string $currency = null,
        /** The record's own state: a lead's stage, an order's payment state. */
        public ?string $state = null,
    ) {}
}
