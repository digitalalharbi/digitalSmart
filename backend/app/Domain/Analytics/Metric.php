<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/**
 * The metrics digitalSmart reports on.
 *
 * Two kinds, and the distinction decides everything downstream. A **base** metric
 * is a number a provider reported and which can be added up. A **derived** metric
 * is a ratio computed from base metrics, and must be computed from the totals
 * rather than averaged from the parts: the mean of seven daily ROAS figures is not
 * the week's ROAS, and the mean of seven CPCs is not the week's CPC.
 *
 * Not every provider reports every metric. That is why `isAvailableFor` exists and
 * why a null result is a first-class answer rather than a bug — see
 * App\Domain\Advertising\MetricSource and docs/INTEGRATIONS.md.
 */
enum Metric: string
{
    // Cost and value
    case Spend = 'spend';
    case Revenue = 'revenue';
    case Roas = 'roas';

    // Delivery
    case Impressions = 'impressions';
    case Reach = 'reach';
    case Frequency = 'frequency';

    // Interaction
    case Clicks = 'clicks';
    case LinkClicks = 'link_clicks';
    case Ctr = 'ctr';
    case LinkCtr = 'link_ctr';
    case Cpc = 'cpc';
    case Cpm = 'cpm';
    case Engagements = 'engagements';

    // Video
    case VideoViews = 'video_views';
    case VideoCompletions = 'video_completions';
    case VideoCompletionRate = 'video_completion_rate';
    case Cpv = 'cpv';

    // Outcome
    case Conversions = 'conversions';
    case ConversionRate = 'conversion_rate';
    case CostPerConversion = 'cpa';
    case Purchases = 'purchases';
    case PurchaseValue = 'purchase_value';
    case Leads = 'leads';
    case CostPerLead = 'cpl';
    case AddToCart = 'add_to_cart';
    case Checkouts = 'checkouts';
    case Installs = 'installs';
    case CostPerInstall = 'cpi';

    /** Whether this is stored and summable, rather than computed from totals. */
    public function isBase(): bool
    {
        return ! $this->isDerived();
    }

    public function isDerived(): bool
    {
        return in_array($this, [
            self::Roas, self::Frequency, self::Ctr, self::LinkCtr, self::Cpc,
            self::Cpm, self::Cpv, self::ConversionRate, self::CostPerConversion,
            self::CostPerLead, self::CostPerInstall, self::VideoCompletionRate,
        ], true);
    }

    /** Whether this is money, and therefore cannot cross currencies. */
    public function isMonetary(): bool
    {
        return in_array($this, [
            self::Spend, self::Revenue, self::PurchaseValue, self::Cpc,
            self::Cpm, self::Cpv, self::CostPerConversion, self::CostPerLead,
            self::CostPerInstall,
        ], true);
    }

    /**
     * Whether summing this across entities is valid.
     *
     * Reach is the one that catches people out: it counts *people*, deduplicated
     * by the platform. Adding two campaigns' reach counts anyone who saw both of
     * them twice, so the sum is always an overstatement and no platform would
     * agree with it. Frequency inherits the problem, being impressions ÷ reach.
     */
    public function isAdditiveAcrossEntities(): bool
    {
        return $this !== self::Reach && $this !== self::Frequency;
    }

    /**
     * The base metrics a derived metric needs.
     *
     * Used to say *which* input is missing when a ratio cannot be computed, so a
     * blank cell carries a reason a person can act on.
     *
     * @return list<self>
     */
    public function dependsOn(): array
    {
        return match ($this) {
            self::Roas => [self::Spend, self::Revenue],
            self::Frequency => [self::Impressions, self::Reach],
            self::Ctr => [self::Impressions, self::Clicks],
            self::LinkCtr => [self::Impressions, self::LinkClicks],
            self::Cpc => [self::Spend, self::Clicks],
            self::Cpm => [self::Spend, self::Impressions],
            self::Cpv => [self::Spend, self::VideoViews],
            self::VideoCompletionRate => [self::VideoViews, self::VideoCompletions],
            self::ConversionRate => [self::Clicks, self::Conversions],
            self::CostPerConversion => [self::Spend, self::Conversions],
            self::CostPerLead => [self::Spend, self::Leads],
            self::CostPerInstall => [self::Spend, self::Installs],
            default => [],
        };
    }

    /**
     * Whether a lower number is better.
     *
     * Needed so a comparison can be coloured correctly: spend falling 20% is not
     * automatically good news, but CPA falling 20% is.
     */
    public function lowerIsBetter(): bool
    {
        return in_array($this, [
            self::Cpc, self::Cpm, self::Cpv, self::CostPerConversion,
            self::CostPerLead, self::CostPerInstall, self::Frequency,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Spend => 'Spend',
            self::Revenue => 'Revenue',
            self::Roas => 'ROAS',
            self::Impressions => 'Impressions',
            self::Reach => 'Reach',
            self::Frequency => 'Frequency',
            self::Clicks => 'Clicks',
            self::LinkClicks => 'Link clicks',
            self::Ctr => 'CTR',
            self::LinkCtr => 'Link CTR',
            self::Cpc => 'CPC',
            self::Cpm => 'CPM',
            self::Engagements => 'Engagements',
            self::VideoViews => 'Video views',
            self::VideoCompletions => 'Video completions',
            self::VideoCompletionRate => 'Completion rate',
            self::Cpv => 'CPV',
            self::Conversions => 'Conversions',
            self::ConversionRate => 'Conversion rate',
            self::CostPerConversion => 'CPA',
            self::Purchases => 'Purchases',
            self::PurchaseValue => 'Purchase value',
            self::Leads => 'Leads',
            self::CostPerLead => 'CPL',
            self::AddToCart => 'Add to cart',
            self::Checkouts => 'Checkouts',
            self::Installs => 'Installs',
            self::CostPerInstall => 'CPI',
        };
    }

    /** How the interface should format it. */
    public function format(): MetricFormat
    {
        return match (true) {
            $this === self::Roas, $this === self::Frequency => MetricFormat::Ratio,
            $this === self::Ctr, $this === self::LinkCtr,
            $this === self::ConversionRate, $this === self::VideoCompletionRate => MetricFormat::Percentage,
            $this->isMonetary() => MetricFormat::Currency,
            default => MetricFormat::Count,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
