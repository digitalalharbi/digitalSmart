<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

/**
 * What one order carried about where it came from.
 *
 * A record of what arrived, not a conclusion about what caused the sale. Everything here was on the
 * order: the parameters on the landing URL, the click identifier the platform appended, the coupon
 * the customer typed. Nothing is inferred, and `AttributionEvidence::None` is a legitimate and
 * common answer.
 *
 * The derivation is in `from()` rather than in a mapper so that every provider reaches the same
 * verdict from the same facts. A Salla order and a Zid order carrying the same UTM set must be
 * attributed identically, or a cross-platform report compares two different rules.
 */
final readonly class OrderAttribution
{
    private function __construct(
        public AttributionEvidence $evidence,
        public ?string $source = null,
        public ?string $medium = null,
        public ?string $campaign = null,
        public ?string $content = null,
        public ?string $term = null,
        /** `gclid`, `fbclid`, `ttclid` and the like, as the order carried it. */
        public ?string $clickIdentifier = null,
        public ?string $couponCode = null,
        public ?string $referrer = null,
    ) {}

    /** An order that said nothing about where it came from. */
    public static function none(): self
    {
        return new self(AttributionEvidence::None);
    }

    /**
     * Work out the strongest evidence this order actually carries.
     *
     * Order matters and is the point: a click identifier beats a UTM set, which beats a coupon,
     * which beats a referrer. All the fields are kept whatever wins, because a report may want to
     * show the channel from a UTM while the link itself rests on the click id.
     */
    public static function from(
        ?string $source = null,
        ?string $medium = null,
        ?string $campaign = null,
        ?string $content = null,
        ?string $term = null,
        ?string $clickIdentifier = null,
        ?string $couponCode = null,
        ?string $referrer = null,
    ): self {
        $source = self::clean($source);
        $medium = self::clean($medium);
        $campaign = self::clean($campaign);
        $clickIdentifier = self::clean($clickIdentifier);
        $couponCode = self::clean($couponCode);
        $referrer = self::clean($referrer);

        $evidence = match (true) {
            $clickIdentifier !== null => AttributionEvidence::ClickIdentifier,
            /*
             * A full set means a campaign can be named. Source *or* medium alone cannot name one —
             * it names a channel — so it is a weaker case rather than the same one.
             */
            $campaign !== null && $source !== null => AttributionEvidence::UtmParameters,
            $source !== null || $medium !== null => AttributionEvidence::PartialUtm,
            $couponCode !== null => AttributionEvidence::CouponCode,
            $referrer !== null => AttributionEvidence::Referrer,
            default => AttributionEvidence::None,
        };

        return new self(
            evidence: $evidence,
            source: $source,
            medium: $medium,
            campaign: $campaign,
            content: self::clean($content),
            term: self::clean($term),
            clickIdentifier: $clickIdentifier,
            couponCode: $couponCode,
            referrer: $referrer,
        );
    }

    /** Whether this order can be credited to a campaign at all. */
    public function canNameCampaign(): bool
    {
        return $this->evidence->identifiesCampaign() && $this->campaignName() !== null;
    }

    /**
     * The campaign this order names, if it names one.
     *
     * The UTM campaign when there is one, otherwise the coupon code — which names *a* campaign only
     * in the sense that somebody minted the code for one, and the evidence level says as much.
     */
    public function campaignName(): ?string
    {
        return $this->campaign ?? ($this->evidence === AttributionEvidence::CouponCode
            ? $this->couponCode
            : null);
    }

    public function confidence(): int
    {
        return $this->evidence->confidence();
    }

    /** Trim, and treat an empty or placeholder value as absent rather than as data. */
    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        /*
         * `(not set)` and `(none)` are what analytics tools write when they have nothing. Storing
         * them verbatim would produce a campaign literally called "(not set)" in a client's report,
         * which is worse than an honest blank: it looks like a campaign somebody named badly.
         */
        return match (strtolower($trimmed)) {
            '', '(not set)', '(none)', 'null', 'undefined' => null,
            default => $trimmed,
        };
    }
}
