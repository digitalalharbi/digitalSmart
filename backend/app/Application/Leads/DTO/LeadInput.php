<?php

declare(strict_types=1);

namespace App\Application\Leads\DTO;

use App\Domain\Commerce\OrderAttribution;
use App\Domain\Leads\LeadSourceKind;
use App\Domain\Leads\LeadStage;
use DateTimeImmutable;

/**
 * A lead as it arrives.
 *
 * `sourceKind` is required and has no default, because the one thing this product must never do is accept a
 * lead without knowing what action created it. Every member of that enum is a completed action by a person.
 */
final readonly class LeadInput
{
    public function __construct(
        public string $clientId,
        public LeadSourceKind $sourceKind,
        public DateTimeImmutable $receivedAt,
        /** Whatever the lead itself carried. Never inferred from the hour it arrived. */
        public OrderAttribution $attribution,
        public ?string $projectId = null,
        public ?string $sourceDetail = null,
        public ?string $externalId = null,
        public ?string $name = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $message = null,
        public ?string $landingPage = null,
        public ?int $valueMinor = null,
        public ?string $currency = null,
        public ?string $storeOrderId = null,
        public ?string $measurementSourceId = null,
        /**
         * The stage the **owning system** states, where one owns it.
         *
         * Set only by a CRM sync, and named for the CRM rather than called `stage` on purpose: for every other
         * source a human owns the stage and a later arrival must not touch it, while a lead that came from a CRM
         * is a read of that CRM and a re-read is authoritative. Null means "nobody else owns this", which is the
         * ordinary case.
         */
        public ?LeadStage $crmStage = null,
        /**
         * When the owning system says it closed.
         *
         * Required with a closed `crmStage`, because the schema requires a closed lead to carry a closing time —
         * the figure that matters most about a pipeline is how long it took, and a row that cannot answer it is
         * worse than no row. **Never "now"**: a deal may have closed last week, and stamping the sync's own clock
         * on it would make every turnaround figure wrong by however long the integration took to arrive.
         */
        public ?DateTimeImmutable $crmClosedAt = null,
        /**
         * Project-specific answers a form collected (city, budget, property type), by key.
         *
         * @var array<string, string>
         */
        public array $custom = [],
    ) {}
}
