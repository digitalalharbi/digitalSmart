<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

use App\Domain\Commerce\AttributionEvidence;

/**
 * One line of a revenue breakdown — a channel, a campaign, a product.
 *
 * `evidence` and `confidence` travel on every attributed row, because a row crediting a campaign on the
 * strength of a coupon code is a different claim from one crediting it on a click identifier, and a
 * report that renders both identically is one that invites a client to act on the weaker one.
 */
final readonly class RevenueBreakdownRow
{
    public function __construct(
        /** The channel, campaign or product name. */
        public string $label,
        public int $orders,
        public int $revenueMinor,
        public int $refundedMinor,
        public int $netRevenueMinor,
        public int $units = 0,
        /**
         * The **weakest** evidence any order in this row rests on, or null for an unattributed row.
         *
         * Weakest rather than strongest, deliberately: a campaign's total is only as defensible as the
         * flimsiest link inside it, and showing the best one would flatter the row.
         */
        public ?AttributionEvidence $evidence = null,
        /** An external id where the row has one — a product id, so a reader can look it up. */
        public ?string $externalId = null,
    ) {}

    public function confidence(): ?int
    {
        return $this->evidence?->confidence();
    }

    /** Whether this row is the honest "we do not know" bucket. */
    public function isUnattributed(): bool
    {
        return $this->evidence === null || $this->evidence === AttributionEvidence::None;
    }
}
