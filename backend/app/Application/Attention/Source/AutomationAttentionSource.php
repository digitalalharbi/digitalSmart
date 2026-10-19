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
use App\Application\Automation\RuleEvaluator;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Domain\Attention\AttentionSeverity;
use App\Domain\Attention\AttentionStream;
use App\Domain\Automation\RuleSeverity;
use App\Domain\Automation\TrustLevel;

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
        private Clock $clock,
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

        $today = $this->clock->now();

        foreach ($rules as $rule) {
            /* Trust level 0 watches silently; it is evaluated by the routine and shown nowhere. */
            if ($rule->trustLevel === TrustLevel::Watch) {
                continue;
            }

            $assessment = $this->campaigns->assessAll($this->scope($rule), $rule->windowDays);
            /* "For three days" is read from what the routine has observed, not guessed from one window. */
            $streaks = $rule->forDays > 1 ? $this->rules->streaks($rule->id) : [];
            $found = 0;

            /** @var list<CampaignControlRow> $rows */
            $rows = $assessment['rows'];

            foreach ($rows as $row) {
                $breach = RuleEvaluator::breach($rule, $row, $today);

                if ($breach === null || ($rule->forDays > 1 && ($streaks[$row->campaignId] ?? 0) < $rule->forDays)) {
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
}
