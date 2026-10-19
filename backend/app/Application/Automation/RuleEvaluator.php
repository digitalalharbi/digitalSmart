<?php

declare(strict_types=1);

namespace App\Application\Automation;

use App\Application\Advertising\DTO\CampaignControlRow;
use App\Application\Automation\DTO\RuleView;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Automation\RuleMetric;
use App\Domain\Automation\RuleOperator;
use DateTimeImmutable;

/**
 * Whether a rule holds for one campaign: the first condition and every further one (AND), over the same verdicts the
 * Campaigns screen shows. Arithmetic only — the sentence, its code and its values for the first condition.
 *
 * A paused or finished campaign holds no rule except "rejected": it is not spending, so there is nothing to act on.
 */
final class RuleEvaluator
{
    /** @return array{0: string, 1: string, 2: array<string, int|string|float>}|null */
    public static function breach(RuleView $rule, CampaignControlRow $row, DateTimeImmutable $today): ?array
    {
        $first = self::one($rule->name, $rule->metric, $rule->threshold, $row, $today);

        if ($first === null) {
            return null;
        }

        foreach ($rule->conditions as $condition) {
            if (self::one($rule->name, $condition['metric'], $condition['threshold'], $row, $today) === null) {
                return null;
            }
        }

        return $first;
    }

    /** @return array{0: string, 1: string, 2: array<string, int|string|float>}|null */
    private static function one(string $name, RuleMetric $metric, ?float $limit, CampaignControlRow $row, DateTimeImmutable $today): ?array
    {
        if ($metric === RuleMetric::Rejected) {
            return $row->status === EntityStatus::Rejected
                ? [sprintf('%s: the platform rejected it.', $name), 'RULE_REJECTED', ['rule' => $name]]
                : null;
        }

        if ($row->status !== EntityStatus::Active) {
            return null;
        }

        if ($metric === RuleMetric::EndsWithin) {
            if ($row->endsAt === null || $limit === null) {
                return null;
            }

            $days = (int) floor(($row->endsAt->getTimestamp() - $today->getTimestamp()) / 86400);

            return $days >= 0 && RuleOperator::Below->breached((float) $days, $limit + 0.5)
                ? [sprintf('%s: it ends in %d days.', $name, $days), 'RULE_ENDS_SOON', ['rule' => $name, 'days' => $days]]
                : null;
        }

        if ($row->spendMinor === null || $row->spendMinor <= 0) {
            return null;
        }

        $currency = $row->currency ?? '';

        return match ($metric) {
            RuleMetric::CostPerResult => $row->costPerResultMinor !== null && $limit !== null && $metric->breachedWhen()->breached($row->costPerResultMinor / 100, $limit)
                ? [sprintf('%s: each result costs %s %s, above the limit of %s.', $name, number_format($row->costPerResultMinor / 100, 2), $currency, self::plain($limit)), 'RULE_COST_OVER', ['rule' => $name, 'value' => round($row->costPerResultMinor / 100, 2), 'currency' => $currency, 'limit' => $limit]]
                : null,
            RuleMetric::Roas => $row->roas !== null && $limit !== null && $metric->breachedWhen()->breached($row->roas, $limit)
                ? [sprintf('%s: ROAS is %s, below the target of %s.', $name, number_format($row->roas, 2), self::plain($limit)), 'RULE_ROAS_UNDER', ['rule' => $name, 'value' => round($row->roas, 2), 'limit' => $limit]]
                : null,
            RuleMetric::BudgetUsed => $row->budgetUtilisation !== null && $limit !== null && $metric->breachedWhen()->breached($row->budgetUtilisation * 100, $limit)
                ? [sprintf('%s: it is spending %s%% of its daily budget, above %s%%.', $name, number_format($row->budgetUtilisation * 100, 0), self::plain($limit)), 'RULE_BUDGET_USED', ['rule' => $name, 'value' => (int) round($row->budgetUtilisation * 100), 'limit' => $limit]]
                : null,
            default => $row->results !== null && $row->results <= 0.0 && $row->reportedDays >= 2
                ? [sprintf('%s: it has spent for %d days with no results recorded.', $name, $row->reportedDays), 'RULE_NO_RESULTS', ['rule' => $name, 'days' => $row->reportedDays]]
                : null,
        };
    }

    private static function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
