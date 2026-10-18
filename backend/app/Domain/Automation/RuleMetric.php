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

    public function label(): string
    {
        return match ($this) {
            self::CostPerResult => 'Cost per result',
            self::Roas => 'ROAS',
            self::BudgetUsed => 'Daily budget used',
            self::NoResults => 'Spending with no results',
        };
    }

    /** Which side of the threshold is a problem. */
    public function breachedWhen(): RuleOperator
    {
        return match ($this) {
            self::CostPerResult, self::BudgetUsed => RuleOperator::Above,
            self::Roas => RuleOperator::Below,
            self::NoResults => RuleOperator::Above,
        };
    }

    public function needsThreshold(): bool
    {
        return $this !== self::NoResults;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
