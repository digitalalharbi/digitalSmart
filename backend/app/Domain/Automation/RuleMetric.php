<?php

declare(strict_types=1);

namespace App\Domain\Automation;

/**
 * What a rule watches.
 *
 * Only figures the product already measures, in the units it already uses — a rule can never reach for a number
 * the campaign pages would not show. Each metric also says which way is bad, so a rule reads as a sentence
 * ("cost per result above 40") and cannot be written backwards.
 */
enum RuleMetric: string
{
    /** Cost per result, in the campaign's own currency (major units, as a person would say them). */
    case CostPerResult = 'cost_per_result';
    case Roas = 'roas';
    /** Average daily spend as a share of the daily budget, in percent. */
    case BudgetUsed = 'budget_used';
    /** Spend with no results at all in the window. Takes no threshold: it is true or it is not. */
    case NoResults = 'no_results';
    /** The platform rejected the campaign. Takes no threshold. */
    case Rejected = 'rejected';
    /** Days until the platform's own end date; a problem at or below the threshold. */
    case EndsWithin = 'ends_within';
    /** Spend over the window, in the campaign's currency. */
    case Spend = 'spend';
    /** Results (the campaign's own objective: purchases, leads, installs…) over the window. */
    case Conversions = 'conversions';
    /** Click-through rate, percent. Only where the platform reported impressions. */
    case Ctr = 'ctr';
    /** Cost per click, in the campaign's currency. */
    case Cpc = 'cpc';
    /** Cost per thousand impressions, in the campaign's currency. */
    case Cpm = 'cpm';
    /** Days since the campaign started (the platform's start date, or its creation). */
    case CampaignAge = 'campaign_age';
    /** Active, and the platform is not delivering it. Takes no threshold. */
    case NotDelivering = 'not_delivering';
    /** Cost per result against the window before, percent change. */
    case CostChange = 'cost_change';

    public function label(): string
    {
        return match ($this) {
            self::CostPerResult => 'Cost per result',
            self::Roas => 'ROAS',
            self::BudgetUsed => 'Daily budget used',
            self::NoResults => 'Spending with no results',
            self::Rejected => 'Rejected by the platform',
            self::EndsWithin => 'Days until it ends',
            self::Spend => 'Spend',
            self::Conversions => 'Results',
            self::Ctr => 'CTR (%)',
            self::Cpc => 'Cost per click',
            self::Cpm => 'Cost per 1,000 impressions',
            self::CampaignAge => 'Days since start',
            self::NotDelivering => 'Not delivering',
            self::CostChange => 'Cost per result change (%)',
        };
    }

    /** Which side of the threshold is a problem. */
    public function breachedWhen(): RuleOperator
    {
        return match ($this) {
            self::CostPerResult, self::BudgetUsed => RuleOperator::Above,
            self::Roas, self::EndsWithin, self::Conversions, self::Ctr => RuleOperator::Below,
            self::NoResults, self::Rejected, self::NotDelivering, self::Spend, self::Cpc, self::Cpm,
            self::CampaignAge, self::CostChange => RuleOperator::Above,
        };
    }

    /** What the threshold is measured in, so the builder can show it the way a person would say it. */
    public function unit(): string
    {
        return match ($this) {
            self::CostPerResult, self::Spend, self::Cpc, self::Cpm => 'money',
            self::Roas => 'ratio',
            self::BudgetUsed, self::Ctr, self::CostChange => 'percent',
            self::EndsWithin, self::CampaignAge => 'days',
            self::Conversions => 'count',
            self::NoResults, self::Rejected, self::NotDelivering => 'state',
        };
    }

    /**
     * Whether the figure exists for a platform at all. Every metric here is computed from the normalised daily
     * figures every adapter reports (spend, impressions, clicks, results, revenue) or from the campaign itself — so a
     * metric is available wherever that platform's figures are synced, and `null` (not judged) where a figure is not
     * reported for a campaign.
     */
    public function needsImpressions(): bool
    {
        return in_array($this, [self::Ctr, self::Cpm], true);
    }

    public function needsThreshold(): bool
    {
        return ! in_array($this, [self::NoResults, self::Rejected, self::NotDelivering], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
