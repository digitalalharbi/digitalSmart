<?php

declare(strict_types=1);

namespace App\Application\Attribution\DTO;

use App\Domain\Attribution\CampaignLink;
use App\Domain\Commerce\AttributionEvidence;

/**
 * One campaign's attributed result, and how firmly it is tied to anything.
 *
 * `spendMinor` and the figures derived from it are null unless this row's link actually joins spend —
 * which only an exact campaign-name match does. A row with revenue and no spend is not a row with
 * zero spend: it means the money was earned and nobody can say what was paid for it, and `link`
 * carries the reason.
 */
final readonly class AttributedCampaignRow
{
    public function __construct(
        public string $label,
        public CampaignLink $link,
        /** The ad platform, where a campaign was matched. */
        public ?string $provider = null,
        public int $conversions = 0,
        public ?int $revenueMinor = null,
        public ?string $currency = null,
        public AttributionEvidence $evidence = AttributionEvidence::None,
        public ?int $spendMinor = null,
        /** The honesty label of the spend figure, which is a different authority from the revenue. */
        public ?string $spendSource = null,
    ) {}

    /**
     * Return on ad spend, or null.
     *
     * Null whenever either side is missing or the spend is zero — and a zero-spend row with revenue
     * is exactly the case where an infinite ROAS would be reported as a triumph. It is also null
     * when the row's link does not join spend at all, because the two numbers would then belong to
     * different things.
     */
    public function roas(): ?float
    {
        if (! $this->link->joinsSpend() || $this->revenueMinor === null) {
            return null;
        }

        if ($this->spendMinor === null || $this->spendMinor <= 0) {
            return null;
        }

        return round($this->revenueMinor / $this->spendMinor, 2);
    }

    /** Cost per attributed conversion, or null where there is nothing to divide. */
    public function costPerConversionMinor(): ?int
    {
        if (! $this->link->joinsSpend() || $this->spendMinor === null || $this->conversions <= 0) {
            return null;
        }

        return intdiv($this->spendMinor, $this->conversions);
    }

    /** Average attributed order value, or null. */
    public function averageRevenueMinor(): ?int
    {
        return $this->revenueMinor === null || $this->conversions <= 0
            ? null
            : intdiv($this->revenueMinor, $this->conversions);
    }
}
