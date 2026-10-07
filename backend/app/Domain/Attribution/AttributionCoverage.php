<?php

declare(strict_types=1);

namespace App\Domain\Attribution;

use App\Domain\Commerce\AttributionEvidence;

/**
 * How much of what happened the attribution actually accounts for.
 *
 * This object exists because an attributed revenue figure on its own is the most quotable
 * misleading number this product could produce. "Meta earned 180,000 riyals" reads as a share of the
 * month; if only 40% of orders carried any marketing information at all, it is a share of a share,
 * and the other 60% is not spread among the campaigns — it is unknown.
 *
 * So coverage travels with every attributed figure, and every rate here is null rather than zero
 * when its denominator is empty.
 */
final readonly class AttributionCoverage
{
    /**
     * Below this, the attributed figures describe too little of the period to lead a report with.
     *
     * Seventy rather than ninety because seventy is roughly what a well-tagged account in this market
     * actually achieves: direct traffic, returning customers, app-to-web journeys and messaging apps
     * that strip parameters all land in the unattributed bucket legitimately. A threshold nobody can
     * reach is a threshold everybody learns to ignore.
     */
    public const int REPORTABLE_PERCENT = 70;

    public function __construct(
        /** Conversions in the period, attributed or not. */
        public int $conversions,
        /** Conversions carrying evidence this model can use. */
        public int $attributedConversions,
        /** Of those, how many name a campaign rather than only a channel. */
        public int $campaignNamedConversions,
        public ?int $revenueMinor,
        public ?int $attributedRevenueMinor,
        public ?string $currency,
        /**
         * The weakest evidence among the attributed conversions.
         *
         * The weakest, not the average: a reader deciding whether to act needs to know the floor of
         * what this figure rests on. A set that is 90% click identifiers and 10% coupon codes is
         * still a set containing coupon codes.
         */
        public AttributionEvidence $weakestEvidence = AttributionEvidence::None,
        /** Revenue-weighted mean confidence of the evidence behind the attributed revenue, 0–100. */
        public ?int $confidence = null,
    ) {}

    /** Share of conversions this model could attribute at all, 0–100, or null. */
    public function conversionCoverage(): ?float
    {
        return $this->rate($this->attributedConversions, $this->conversions);
    }

    /**
     * Share of revenue the attributed figure accounts for, 0–100, or null.
     *
     * Usually lower than the conversion coverage and occasionally much higher, because the orders
     * that carry parameters are not a random sample of orders. Both are published for that reason.
     */
    public function revenueCoverage(): ?float
    {
        return $this->rate($this->attributedRevenueMinor, $this->revenueMinor);
    }

    /**
     * Of the conversions this model attributed, how many name a campaign.
     *
     * A referrer or a bare `utm_source` names a channel. Splitting a channel's revenue across that
     * client's campaigns on that basis is inventing the split, so those rows are counted here and
     * reported as channel-only rather than being distributed.
     */
    public function campaignNamedShare(): ?float
    {
        return $this->rate($this->campaignNamedConversions, $this->attributedConversions);
    }

    /** Revenue the period produced that no campaign can be credited with. Null when unknown. */
    public function unattributedRevenueMinor(): ?int
    {
        if ($this->revenueMinor === null || $this->attributedRevenueMinor === null) {
            return null;
        }

        return max(0, $this->revenueMinor - $this->attributedRevenueMinor);
    }

    /**
     * Whether these figures describe enough of the period to lead with.
     *
     * False does not mean hide them. It means the interface leads with the coverage instead, because
     * a 30%-coverage channel report put in front of a client without that sentence is a report that
     * will be quoted as if it were the whole month.
     */
    public function isReportable(): bool
    {
        $coverage = $this->revenueCoverage() ?? $this->conversionCoverage();

        return $coverage !== null && $coverage >= self::REPORTABLE_PERCENT;
    }

    private function rate(?int $numerator, ?int $denominator): ?float
    {
        if ($numerator === null || $denominator === null || $denominator <= 0) {
            return null;
        }

        return round(($numerator / $denominator) * 100, 1);
    }
}
