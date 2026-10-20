<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

use App\Domain\Shared\ValueObject\DateRange;

/**
 * A shared report's scope after a visitor's filters have been intersected with it.
 *
 * Built only by `NarrowSharedReport`, from values it has already found inside the link's scope — internal ids, not the
 * opaque keys the visitor sent. Applying it can only remove rows from what the report would have shown.
 */
final readonly class ReportNarrowing
{
    /**
     * @param  list<string>  $providers
     * @param  list<string>  $campaignIds
     */
    public function __construct(
        public ?DateRange $period = null,
        public array $providers = [],
        public array $campaignIds = [],
        public ?string $storeId = null,
    ) {}

    public function isActive(): bool
    {
        return $this->period !== null || $this->providers !== [] || $this->campaignIds !== [] || $this->storeId !== null;
    }

    /** Whether a figure about ad platforms or campaigns is being narrowed. Leads and stores cannot follow that. */
    public function narrowsAdvertising(): bool
    {
        return $this->providers !== [] || $this->campaignIds !== [];
    }
}
