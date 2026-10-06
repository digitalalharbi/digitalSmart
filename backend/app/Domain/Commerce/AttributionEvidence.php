<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

/**
 * What an order itself carried that links it to marketing.
 *
 * This enum exists to make one rule enforceable: **an order is never assigned to a campaign
 * without the order saying so.** Not by the hour it arrived, not by the customer's city, not by
 * being the only order on a day a campaign ran. Those correlations are tempting, they are invisible
 * once they reach a report, and acting on them means telling a client which campaign earned money
 * on the strength of a coincidence.
 *
 * So every link carries the evidence it rests on, and the evidence has a strength. A click
 * identifier was put there by the platform; a UTM set was put there by whoever built the link; a
 * coupon code was typed by a human who may have found it anywhere. Those are not the same claim and
 * this product will not render them as one.
 */
enum AttributionEvidence: string
{
    /**
     * A platform's own click identifier — `gclid`, `fbclid`, `ttclid`.
     *
     * The strongest link available outside a logged-in platform join: the platform generated it when
     * the click happened, and its presence on the order means that click reached the checkout.
     */
    case ClickIdentifier = 'click_identifier';

    /** A full UTM set. Put there deliberately, by whoever built the link. */
    case UtmParameters = 'utm_parameters';

    /** Only a source or medium — enough to name a channel, not a campaign. */
    case PartialUtm = 'partial_utm';

    /**
     * A coupon code tied to a campaign.
     *
     * Weak on purpose. A code is typed by a person who may have had it from a friend, an influencer,
     * a screenshot or a coupon aggregator, and attributing full revenue to the campaign that minted
     * it routinely credits the wrong one.
     */
    case CouponCode = 'coupon_code';

    /** A referring site. Names a channel at best, and is lost entirely on most mobile apps. */
    case Referrer = 'referrer';

    /**
     * Nothing. The order arrived with no marketing information at all.
     *
     * The most important case, and the one a dishonest implementation would quietly fill in.
     * Direct, organic, a returning customer and a lost UTM all land here, and the correct report
     * says "unattributed" rather than dividing them among the campaigns that were running.
     */
    case None = 'none';

    /**
     * How much weight a link on this evidence can carry, from 0 to 100.
     *
     * Published as a number so a report can show it beside the revenue rather than burying it in a
     * footnote, and so a model in a later phase can weight by it instead of treating every link as
     * certain.
     */
    public function confidence(): int
    {
        return match ($this) {
            self::ClickIdentifier => 95,
            self::UtmParameters => 80,
            self::PartialUtm => 50,
            // Deliberately below half: a coupon says somebody saw a campaign, not that it sold.
            self::CouponCode => 35,
            self::Referrer => 25,
            self::None => 0,
        };
    }

    /**
     * Whether this evidence can name a *campaign*, as opposed to a channel.
     *
     * A referrer and a bare `utm_source` say traffic came from Meta. Neither says which campaign,
     * and a report that splits revenue across a client's Meta campaigns on that basis is inventing
     * the split.
     */
    public function identifiesCampaign(): bool
    {
        return $this === self::ClickIdentifier
            || $this === self::UtmParameters
            || $this === self::CouponCode;
    }

    public function label(): string
    {
        return match ($this) {
            self::ClickIdentifier => 'Click identifier',
            self::UtmParameters => 'Campaign parameters',
            self::PartialUtm => 'Source only',
            self::CouponCode => 'Coupon code',
            self::Referrer => 'Referring site',
            self::None => 'Unattributed',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
