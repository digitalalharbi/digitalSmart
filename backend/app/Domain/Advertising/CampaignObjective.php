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
}
