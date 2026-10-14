<?php

declare(strict_types=1);

namespace App\Domain\Advertising;

/**
 * Normalised campaign objectives.
 *
 * Providers name these differently and keep changing them — Meta's ODAX
 * migration renamed its whole set to OUTCOME_*, while the legacy names still
 * appear on older campaigns. The provider's raw value is always preserved; this
 * enum exists so "all lead campaigns across every platform" is answerable.
 */
enum CampaignObjective: string
{
    case Awareness = 'awareness';
    case Reach = 'reach';
    case Traffic = 'traffic';
    case Engagement = 'engagement';
    case VideoViews = 'video_views';
    case Leads = 'leads';
    case AppPromotion = 'app_promotion';
    case Sales = 'sales';
    case StoreTraffic = 'store_traffic';
    case Messages = 'messages';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Awareness => 'Awareness',
            self::Reach => 'Reach',
            self::Traffic => 'Traffic',
            self::Engagement => 'Engagement',
            self::VideoViews => 'Video views',
            self::Leads => 'Leads',
            self::AppPromotion => 'App promotion',
            self::Sales => 'Sales',
            self::StoreTraffic => 'Store traffic',
            self::Messages => 'Messages',
            self::Other => 'Other',
        };
    }

    /**
     * The metric this objective is usually judged by.
     *
     * Used to pick a sensible default column when showing a mixed list, so a
     * reach campaign is not ranked on purchases it was never meant to drive.
     */
    public function primaryMetric(): string
    {
        return match ($this) {
            self::Awareness, self::Reach => 'reach',
            self::Traffic => 'link_clicks',
            self::Engagement, self::Messages => 'engagements',
            self::VideoViews => 'video_views',
            self::Leads => 'leads',
            self::AppPromotion => 'installs',
            self::Sales, self::StoreTraffic => 'purchases',
            self::Other => 'impressions',
        };
    }

    /**
     * The measured column that stands for this objective's result.
     *
     * Distinct from `primaryMetric()`, which is the *name* a reader expects — "purchases", "installs" —
     * and is not a column anywhere. This is what the fact table actually holds, and the pair exists
     * because collapsing them would mean either labelling a lead campaign's figure "conversions" or
     * inventing an `installs` column that no provider fills.
     *
     * Everything that counts an action lands on `conversions`, which is the honest position: the product
     * knows a conversion happened and takes the *kind* from the event definition, not from the campaign's
     * objective.
     */
    public function resultColumn(): string
    {
        return match ($this) {
            self::Awareness, self::Reach => 'reach',
            self::Traffic => 'link_clicks',
            self::Engagement, self::Messages => 'engagements',
            self::VideoViews => 'video_views',
            self::Leads, self::AppPromotion, self::Sales, self::StoreTraffic => 'conversions',
            self::Other => 'impressions',
        };
    }

    /**
     * Whether a cost-per-result figure means anything for this objective.
     *
     * Cost per thousand impressions is a real figure; "cost per reach" is not one anybody uses, and
     * dividing spend by impressions and calling it a result would be inventing a metric.
     */
    public function hasCostPerResult(): bool
    {
        return $this !== self::Other && $this !== self::Awareness && $this !== self::Reach;
    }

    /**
     * Whether revenue — and therefore ROAS — can be read against this objective.
     *
     * **False for everything but sales.** Rule 33 says it plainly: do not show ROAS as the primary metric
     * for campaigns that do not measure revenue. A lead campaign has no revenue of its own; whatever those
     * leads eventually became is an attribution question answered elsewhere, and putting a return figure
     * on the campaign row would hand a client a number the campaign cannot support.
     */
    public function measuresRevenue(): bool
    {
        return $this === self::Sales || $this === self::StoreTraffic;
    }
}
