<?php

declare(strict_types=1);

namespace App\Application\Automation;

use App\Application\Advertising\DTO\CampaignControlRow;
use App\Application\Automation\DTO\RuleView;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Automation\RuleAction;
use App\Domain\Automation\RuleMetric;
use App\Domain\Automation\RuleOperator;
use DateTimeImmutable;

/**
 * Whether a rule holds for one campaign, over the same figures the Campaigns screen shows.
 *
 * Every condition is a metric, a direction and (where it takes one) a limit; they combine with AND (`all`) or OR
 * (`any`). A figure the platform did not report is not judged — a missing CTR is not a low CTR — so a condition on
 * it is simply not met. A paused or finished campaign holds no condition except "rejected": it is not spending, so
 * there is nothing to act on. Arithmetic only; the sentence, its code and its values describe the first condition.
 */
final class RuleEvaluator
{
    /** @return array{0: string, 1: string, 2: array<string, int|string|float>}|null */
    public static function breach(RuleView $rule, CampaignControlRow $row, DateTimeImmutable $today): ?array
    {
        $result = self::evaluate($rule, $row, $today);

        return $result['holds'] ? $result['first'] : null;
    }

    /**
     * Every condition's figure and verdict, and whether the rule holds — for the dry run, the history's "before"
     * values, and the routine.
     *
     * @return array{holds: bool, sufficient: bool, values: array<string, float|int|null>, met: array<string, bool>, first: array{0: string, 1: string, 2: array<string, int|string|float>}|null}
     */
    public static function evaluate(RuleView $rule, CampaignControlRow $row, DateTimeImmutable $today): array
    {
        $sufficient = self::sufficient($rule, $row, $today);

        $conditions = [['metric' => $rule->metric, 'threshold' => $rule->threshold, 'operator' => $rule->operator], ...array_map(
            static fn (array $c): array => ['metric' => $c['metric'], 'threshold' => $c['threshold'], 'operator' => $c['operator'] ?? $c['metric']->breachedWhen()],
            $rule->conditions,
        )];

        $values = [];
        $met = [];

        foreach ($conditions as $c) {
            $value = self::value($c['metric'], $row, $today);
            $values[$c['metric']->value] = $value;
            $met[$c['metric']->value] = self::meets($c['metric'], $c['operator'], $c['threshold'], $value, $row, $rule->action === RuleAction::ProposeResume);
        }

        $holds = $sufficient && ($rule->logic === 'any' ? in_array(true, $met, true) : ! in_array(false, $met, true));

        return [
            'holds' => $holds,
            'sufficient' => $sufficient,
            'values' => $values,
            'met' => $met,
            'first' => $holds ? self::sentence($rule, $conditions[0], $values[$conditions[0]['metric']->value] ?? null, $row) : null,
        ];
    }

    /**
     * Enough data to judge? A campaign below the rule's minimum spend, age or results is not judged — however its
     * figures look. A missing figure counts as not enough.
     */
    public static function sufficient(RuleView $rule, CampaignControlRow $row, DateTimeImmutable $today): bool
    {
        if ($rule->minSpend !== null && ($row->spendMinor === null || $row->spendMinor / 100 < $rule->minSpend)) {
            return false;
        }

        if ($rule->minAgeDays !== null) {
            $age = self::value(RuleMetric::CampaignAge, $row, $today);

            if ($age === null || $age < $rule->minAgeDays) {
                return false;
            }
        }

        return $rule->minConversions === null || ($row->results !== null && $row->results >= $rule->minConversions);
    }

    /** The figure a metric reads for this campaign, in the unit a person states the limit in; null when unreported. */
    public static function value(RuleMetric $metric, CampaignControlRow $row, DateTimeImmutable $today): float|int|null
    {
        $spend = $row->spendMinor === null ? null : $row->spendMinor / 100;

        return match ($metric) {
            RuleMetric::CostPerResult => $row->costPerResultMinor === null ? null : round($row->costPerResultMinor / 100, 2),
            RuleMetric::Roas => $row->roas === null ? null : round($row->roas, 2),
            RuleMetric::BudgetUsed => $row->budgetUtilisation === null ? null : (int) round($row->budgetUtilisation * 100),
            RuleMetric::Spend => $spend,
            RuleMetric::Conversions => $row->results,
            RuleMetric::Ctr => $row->impressions === null || $row->impressions === 0 || $row->clicks === null ? null : round($row->clicks / $row->impressions * 100, 2),
            RuleMetric::Cpc => $spend === null || $row->clicks === null || $row->clicks === 0 ? null : round($spend / $row->clicks, 2),
            RuleMetric::Cpm => $spend === null || $row->impressions === null || $row->impressions === 0 ? null : round($spend / $row->impressions * 1000, 2),
            RuleMetric::CampaignAge => $row->startedAt === null ? null : max(0, (int) floor(($today->getTimestamp() - $row->startedAt->getTimestamp()) / 86400)),
            RuleMetric::EndsWithin => $row->endsAt === null ? null : (int) floor(($row->endsAt->getTimestamp() - $today->getTimestamp()) / 86400),
            RuleMetric::CostChange => $row->healthReasonCode === 'CAMPAIGN_COST_RISE' ? (int) ($row->healthReasonParams['pct'] ?? 0) : null,
            RuleMetric::NoResults => $row->results === null ? null : ($row->results <= 0.0 && $row->reportedDays >= 2 ? 1 : 0),
            RuleMetric::Rejected => $row->status === EntityStatus::Rejected ? 1 : 0,
            RuleMetric::NotDelivering => $row->status === EntityStatus::NotDelivering ? 1 : 0,
        };
    }

    /** A resume rule judges paused campaigns — that is what it is for; every other rule judges live ones. */
    private static function meets(RuleMetric $metric, RuleOperator $operator, ?float $limit, float|int|null $value, CampaignControlRow $row, bool $forResume = false): bool
    {
        if ($metric === RuleMetric::Rejected) {
            return $value === 1;
        }

        if ($metric === RuleMetric::NotDelivering) {
            return $value === 1;
        }

        $judged = $forResume ? EntityStatus::Paused : EntityStatus::Active;

        if ($row->status !== $judged || $value === null) {
            return false;
        }

        if ($metric === RuleMetric::NoResults) {
            return $value === 1 && ($row->spendMinor ?? 0) > 0;
        }

        if ($metric === RuleMetric::EndsWithin) {
            return $limit !== null && $value >= 0 && $value <= $limit;
        }

        if ($limit === null) {
            return false;
        }

        /* A campaign that spent nothing is judged on nothing, except for its age and what it spent. */
        if (! $forResume && ! in_array($metric, [RuleMetric::CampaignAge, RuleMetric::Spend, RuleMetric::Conversions], true) && ($row->spendMinor ?? 0) <= 0) {
            return false;
        }

        return $operator->breached((float) $value, $limit);
    }

    /**
     * @param  array{metric: RuleMetric, threshold: float|null, operator: RuleOperator}  $c
     * @return array{0: string, 1: string, 2: array<string, int|string|float>}
     */
    private static function sentence(RuleView $rule, array $c, float|int|null $value, CampaignControlRow $row): array
    {
        $name = $rule->name;
        $currency = $row->currency ?? '';
        $limit = $c['threshold'];
        $v = $value ?? 0;

        return match ($c['metric']) {
            RuleMetric::CostPerResult => [sprintf('%s: each result costs %s %s, against the limit of %s.', $name, number_format((float) $v, 2), $currency, self::plain($limit)), 'RULE_COST_OVER', ['rule' => $name, 'value' => $v, 'currency' => $currency, 'limit' => (float) $limit]],
            RuleMetric::Roas => [sprintf('%s: ROAS is %s, against %s.', $name, number_format((float) $v, 2), self::plain($limit)), 'RULE_ROAS_UNDER', ['rule' => $name, 'value' => $v, 'limit' => (float) $limit]],
            RuleMetric::BudgetUsed => [sprintf('%s: it is spending %d%% of its daily budget, against %s%%.', $name, (int) $v, self::plain($limit)), 'RULE_BUDGET_USED', ['rule' => $name, 'value' => (int) $v, 'limit' => (float) $limit]],
            RuleMetric::NoResults => [sprintf('%s: it has spent for %d days with no results recorded.', $name, $row->reportedDays), 'RULE_NO_RESULTS', ['rule' => $name, 'days' => $row->reportedDays]],
            RuleMetric::Rejected => [sprintf('%s: the platform rejected it.', $name), 'RULE_REJECTED', ['rule' => $name]],
            RuleMetric::EndsWithin => [sprintf('%s: it ends in %d days.', $name, (int) $v), 'RULE_ENDS_SOON', ['rule' => $name, 'days' => (int) $v]],
            RuleMetric::NotDelivering => [sprintf('%s: active, and the platform is not delivering it.', $name), 'RULE_NOT_DELIVERING', ['rule' => $name]],
            default => [sprintf('%s: %s is %s, against %s.', $name, $c['metric']->label(), self::plain((float) $v), self::plain($limit)), 'RULE_METRIC', ['rule' => $name, 'metric' => $c['metric']->value, 'value' => $v, 'limit' => (float) $limit, 'currency' => $currency]],
        };
    }

    private static function plain(?float $value): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
