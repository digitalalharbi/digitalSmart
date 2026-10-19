<?php

declare(strict_types=1);

namespace App\Application\Attention\Source;

use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\DTO\CampaignControlRow;
use App\Application\Advertising\Query\GetCampaignControl;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Attention\Contract\AttentionSource;
use App\Application\Attention\DTO\AttentionItem;
use App\Application\Automation\Contract\AutomationRuleRepository;
use App\Application\Automation\DTO\RuleView;
use App\Application\Shared\Contract\ActorContext;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Attention\AttentionSeverity;
use App\Domain\Attention\AttentionStream;
use App\Domain\Automation\RuleMetric;
use App\Domain\Automation\RuleSeverity;

/**
 * What the agency's own rules have noticed.
 *
 * Rules are evaluated when the queue is read, over the same campaign verdicts the control center shows — no stored
 * "triggered" state to drift out of date, and a campaign that recovers leaves the queue by itself. The only outcome
 * a rule has is an item here; it cannot pause, edit or spend anything.
 */
final readonly class AutomationAttentionSource implements AttentionSource
{
    /** Per rule: past this the list has stopped pointing at a problem and started being the problem. */
    private const int PER_RULE = 8;

    public function __construct(
        private AutomationRuleRepository $rules,
        private GetCampaignControl $campaigns,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function stream(): AttentionStream
    {
        return AttentionStream::Automation;
    }

    /** @return list<AttentionItem> */
    public function items(): array
    {
        $rules = $this->rules->visibleTo(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            activeOnly: true,
        );

        $items = [];

        foreach ($rules as $rule) {
            $assessment = $this->campaigns->assessAll($this->scope($rule), $rule->windowDays);
            $found = 0;

            /** @var list<CampaignControlRow> $rows */
            $rows = $assessment['rows'];

            foreach ($rows as $row) {
                $breach = $this->breach($rule, $row);

                if ($breach === null) {
                    continue;
                }

                [$message, $code, $params] = $breach;

                $items[] = new AttentionItem(
                    id: sprintf('rule:%s:%s', $rule->id, $row->campaignId),
                    stream: AttentionStream::Automation,
                    severity: match ($rule->severity) {
                        RuleSeverity::Critical => AttentionSeverity::Critical,
                        RuleSeverity::NeedsAttention => AttentionSeverity::NeedsAttention,
                        RuleSeverity::Watch => AttentionSeverity::Watch,
                    },
                    message: $message,
                    entityKind: 'Campaign',
                    entityLabel: $row->campaignName,
                    owner: false,
                    since: null,
                    clientId: $row->clientId,
                    clientName: $row->clientName,
                    projectId: $row->projectId,
                    projectName: $row->projectName,
                    reasonCode: $code,
                    reasonParams: $params,
                );

                if (++$found >= self::PER_RULE) {
                    break;
                }
            }
        }

        return $items;
    }

    private function scope(RuleView $rule): CampaignControlFilter
    {
        return new CampaignControlFilter(
            clientIds: $rule->clientId === null ? [] : [$rule->clientId],
            projectIds: $rule->projectId === null ? [] : [$rule->projectId],
        );
    }

    /**
     * The breach as a sentence, a code and its values — or null when the campaign is within the rule.
     *
     * @return array{0: string, 1: string, 2: array<string, int|string|float>}|null
     */
    private function breach(RuleView $rule, CampaignControlRow $row): ?array
    {
        /* A paused or finished campaign is not a problem to act on, whatever its last figures were. */
        if ($row->status !== EntityStatus::Active || $row->spendMinor === null || $row->spendMinor <= 0) {
            return null;
        }

        $currency = $row->currency ?? '';
        $limit = $rule->threshold;

        return match ($rule->metric) {
            RuleMetric::CostPerResult => $row->costPerResultMinor !== null && $limit !== null && $rule->operator->breached($row->costPerResultMinor / 100, $limit)
                ? [sprintf('%s: each result costs %s %s, above the limit of %s.', $rule->name, number_format($row->costPerResultMinor / 100, 2), $currency, $this->plain($limit)), 'RULE_COST_OVER', ['rule' => $rule->name, 'value' => round($row->costPerResultMinor / 100, 2), 'currency' => $currency, 'limit' => $limit]]
                : null,
            RuleMetric::Roas => $row->roas !== null && $limit !== null && $rule->operator->breached($row->roas, $limit)
                ? [sprintf('%s: ROAS is %s, below the target of %s.', $rule->name, number_format($row->roas, 2), $this->plain($limit)), 'RULE_ROAS_UNDER', ['rule' => $rule->name, 'value' => round($row->roas, 2), 'limit' => $limit]]
                : null,
            RuleMetric::BudgetUsed => $row->budgetUtilisation !== null && $limit !== null && $rule->operator->breached($row->budgetUtilisation * 100, $limit)
                ? [sprintf('%s: it is spending %s%% of its daily budget, above %s%%.', $rule->name, number_format($row->budgetUtilisation * 100, 0), $this->plain($limit)), 'RULE_BUDGET_USED', ['rule' => $rule->name, 'value' => (int) round($row->budgetUtilisation * 100), 'limit' => $limit]]
                : null,
            RuleMetric::NoResults => $row->results !== null && $row->results <= 0.0 && $row->reportedDays >= 2
                ? [sprintf('%s: it has spent for %d days with no results recorded.', $rule->name, $row->reportedDays), 'RULE_NO_RESULTS', ['rule' => $rule->name, 'days' => $row->reportedDays]]
                : null,
        };
    }

    private function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
