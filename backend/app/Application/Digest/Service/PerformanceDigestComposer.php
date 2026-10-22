<?php

declare(strict_types=1);

namespace App\Application\Digest\Service;

use App\Application\Analytics\Contract\AnalyticsReadModel;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Analytics\DTO\BreakdownRow;
use App\Application\Analytics\DTO\MetricSet;
use App\Application\Analytics\Service\CreativeIntelligenceReader;
use App\Application\Attention\DTO\AttentionItem;
use App\Application\Attention\Query\GetAttentionQueue;
use App\Application\Budget\Query\GetBudgetControl;
use App\Application\Digest\DTO\PerformanceDigestPeriod;
use App\Application\Notifications\DTO\NotificationPreferences;
use App\Application\Shared\Contract\ActorContext;
use App\Domain\Access\Permission;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Attention\DigestKind;
use App\Domain\Notifications\NotificationSeverity;

/**
 * One person's performance digest, as figures.
 *
 * **Nothing here computes a metric.** The headline figures are the analytics layer's own summary with its own
 * comparison; the platforms and campaigns are its breakdowns; the creative is the creative layer's verdict; the
 * action cards are the attention queue's own items; budgets are the finance page's own rows. A digest with its own
 * arithmetic would be a second set of numbers, and the reconciliation happens in somebody's inbox.
 *
 * **Role-aware by permission, not by role name.** The reader's permissions decide which parts are composed at all:
 * no `budgets.view`, no budget part; no `creative.view`, no creative part. The analytics scope is theirs — the
 * projects they can see — so a buyer on no project gets nothing, which is the truth.
 *
 * **Best and weakest are a rule, stated once.** Rank by ROAS where at least two platforms report one; otherwise by
 * cost per result where two report one; otherwise by results. A platform with nothing spent is neither.
 */
final readonly class PerformanceDigestComposer
{
    private const int ACTION_CARDS = 5;

    private const int CAMPAIGNS = 20;

    private const int RECOMMENDATIONS = 3;

    /** @var list<Metric> */
    private const array HEADLINE = [
        Metric::Spend, Metric::Revenue, Metric::Roas, Metric::Conversions, Metric::CostPerConversion,
        Metric::Leads, Metric::CostPerLead, Metric::Purchases, Metric::Clicks, Metric::Impressions,
    ];

    /** @var list<Metric> */
    private const array RANKED = [Metric::Spend, Metric::Roas, Metric::CostPerConversion, Metric::Conversions, Metric::Ctr];

    public function __construct(
        private AnalyticsReadModel $analytics,
        private VisibilityResolver $visibility,
        private GetAttentionQueue $queue,
        private GetBudgetControl $budgets,
        private CreativeIntelligenceReader $creatives,
        private ActorContext $actor,
    ) {}

    /**
     * @return array<string, mixed>|null null when the reader has nothing to be told
     */
    public function compose(string $workspaceId, PerformanceDigestPeriod $period, ?NotificationPreferences $preferences = null): ?array
    {
        $permissions = $this->actor->permissions();
        $filters = $this->scope($preferences);

        /* No figures to read — no analytics permission, or no project in sight — is no performance digest at all. */
        if ($filters === null) {
            return null;
        }

        $summary = $this->analytics->summary($workspaceId, $period->current, $filters, self::HEADLINE, $period->previous);
        $platforms = $this->analytics->breakdown($workspaceId, $period->current, $filters, Dimension::Provider, self::RANKED, Metric::Spend);
        $campaigns = $this->analytics->breakdown($workspaceId, $period->current, $filters, Dimension::Campaign, self::RANKED, Metric::Spend, self::CAMPAIGNS);

        $attention = ($this->queue)($period->kind->streams(), self::ACTION_CARDS);
        $cards = array_map(static fn (AttentionItem $item): array => [
            'message' => $item->message,
            'severity' => $item->severity->value,
            'stream' => $item->stream->value,
            'entity_kind' => $item->entityKind,
            'entity' => $item->entityLabel,
            'client' => $item->clientName,
            'project' => $item->projectName,
        ], array_slice($attention->items, 0, self::ACTION_CARDS));

        if ($summary->isEmpty() && $attention->total === 0) {
            return null;
        }

        $ranked = $this->rank($platforms);
        $topCampaigns = $this->rank($campaigns);
        $budget = $permissions->has(Permission::BudgetsView) ? $this->budget() : null;

        $digest = [
            'cadence' => $period->kind->value,
            'period' => ['start' => $period->current->startDate(), 'end' => $period->current->endDate(), 'days' => $period->current->lengthInDays()],
            'compared_with' => ['start' => $period->previous->startDate(), 'end' => $period->previous->endDate()],
            'figures' => $this->figures($summary),
            'source' => $summary->source->value,
            'currency' => $summary->currency,
            'platforms' => array_map($this->row(...), $platforms),
            'best_platform' => $ranked['best'] === null ? null : $this->row($ranked['best']),
            'weakest_platform' => $ranked['weakest'] === null ? null : $this->row($ranked['weakest']),
            'ranked_by' => $ranked['by'],
            'top_campaigns' => array_map($this->row(...), array_slice($topCampaigns['ordered'], 0, 5)),
            'top_campaign' => $topCampaigns['best'] === null ? null : $this->row($topCampaigns['best']),
            'attention_campaign' => $this->attentionCampaign($attention->items),
            'active_campaigns' => count($campaigns),
            'needs_attention' => $attention->total,
            'by_severity' => $attention->severityCounts,
            'cards' => $cards,
            'budget' => $budget,
            'creative' => $this->creative($workspaceId, $period, $filters),
        ];

        /* A daily digest says what changed; the weekly and monthly ones may also say what to do about it. */
        $digest['recommendations'] = $period->kind === DigestKind::DailyPerformance ? [] : $this->recommendations($digest);
        $digest['severity'] = $this->severity($digest)->value;

        return $digest;
    }

    /**
     * The reader's scope, narrowed by what they asked for. A preference can only narrow: a project they chose that
     * they cannot see is dropped, and a scope that leaves nothing is no digest.
     */
    private function scope(?NotificationPreferences $preferences): ?AnalyticsFilters
    {
        if (! $this->actor->permissions()->has(Permission::AnalyticsView)) {
            return null;
        }

        $visible = $this->visibility->visibleProjectIds();

        if ($visible === []) {
            return null;
        }

        $projects = $visible ?? [];
        $chosen = $preferences?->projectIds;

        if ($chosen !== null) {
            $projects = $visible === null ? $chosen : array_values(array_intersect($chosen, $visible));

            if ($projects === []) {
                return null;
            }
        }

        return new AnalyticsFilters(projectIds: $projects, clientId: $preferences?->clientId);
    }

    /**
     * Each headline figure as the analytics layer published it: value, earlier value, change, and the reason when
     * there is none. Never a zero for a figure nobody reported.
     *
     * @return array<string, array<string, mixed>>
     */
    private function figures(MetricSet $set): array
    {
        $out = [];

        foreach (self::HEADLINE as $metric) {
            $value = $set->get($metric);

            if ($value === null) {
                continue;
            }

            $out[$metric->value] = [
                'label' => $metric->label(),
                'value' => $value->value,
                'format' => $value->format->value,
                'currency' => $value->currency,
                'comparison_value' => $value->comparisonValue,
                'change_ratio' => $value->changeRatio(),
                'change_is_good' => $value->changeIsGood(),
                'unavailable_reason' => $value->unavailableReason,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function row(BreakdownRow $row): array
    {
        $figure = static function (Metric $metric) use ($row): ?array {
            $value = $row->metrics->get($metric);

            return $value === null ? null : ['value' => $value->value, 'currency' => $value->currency, 'change_ratio' => $value->changeRatio()];
        };

        return [
            /* The platform slug is a public name; a campaign's key is an internal id and is not carried. */
            'key' => $row->dimension === Dimension::Provider ? $row->key : null,
            'label' => $row->label,
            'spend' => $figure(Metric::Spend),
            'roas' => $figure(Metric::Roas),
            'cpa' => $figure(Metric::CostPerConversion),
            'results' => $figure(Metric::Conversions),
            'ctr' => $figure(Metric::Ctr),
        ];
    }

    /**
     * Best and weakest by one stated rule, over rows that spent something.
     *
     * @param  list<BreakdownRow>  $rows
     * @return array{best: ?BreakdownRow, weakest: ?BreakdownRow, by: ?string, ordered: list<BreakdownRow>}
     */
    private function rank(array $rows): array
    {
        $spent = array_values(array_filter($rows, static fn (BreakdownRow $row): bool => ($row->metrics->get(Metric::Spend)->value ?? 0) > 0));

        foreach ([[Metric::Roas, false], [Metric::CostPerConversion, true], [Metric::Conversions, false]] as [$metric, $lowerIsBetter]) {
            $with = array_values(array_filter($spent, static fn (BreakdownRow $row): bool => $row->metrics->get($metric)?->value !== null));

            if (count($with) < 2) {
                continue;
            }

            usort($with, static function (BreakdownRow $a, BreakdownRow $b) use ($metric, $lowerIsBetter): int {
                $x = (float) ($a->metrics->get($metric)->value ?? 0);
                $y = (float) ($b->metrics->get($metric)->value ?? 0);

                return $lowerIsBetter ? $x <=> $y : $y <=> $x;
            });

            return ['best' => $with[0], 'weakest' => $with[count($with) - 1], 'by' => $metric->value, 'ordered' => $with];
        }

        return ['best' => null, 'weakest' => null, 'by' => null, 'ordered' => $spent];
    }

    /**
     * @param  list<AttentionItem>  $items
     * @return array<string, mixed>|null
     */
    private function attentionCampaign(array $items): ?array
    {
        foreach ($items as $item) {
            if ($item->entityKind === 'campaign') {
                return ['label' => $item->entityLabel, 'message' => $item->message, 'severity' => $item->severity->value, 'client' => $item->clientName];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function budget(): array
    {
        $rows = ($this->budgets)();
        $atRisk = array_values(array_filter($rows, static fn ($row): bool => $row->risks !== []));
        $worst = $atRisk[0] ?? null;

        return [
            'budgets' => count($rows),
            'at_risk' => count($atRisk),
            'worst' => $worst === null ? null : [
                'label' => $worst->name,
                'client' => $worst->clientName,
                'risk' => $worst->risks[0] ?? null,
                'currency' => $worst->currency,
                'approved' => $worst->approvedMinor,
                'spent' => $worst->spentMinor,
                'utilisation' => $worst->pacing?->utilisation(),
            ],
        ];
    }

    /** @return array<string, mixed>|null the one creative wearing out, if any */
    private function creative(string $workspaceId, PerformanceDigestPeriod $period, AnalyticsFilters $filters): ?array
    {
        $read = $this->creatives->read($workspaceId, $period->current, $filters, 'asset', null, 'fatiguing', 1);
        $row = $read->rows[0] ?? null;

        if ($row === null) {
            return null;
        }

        $evidence = [];

        foreach ($row->assessment->evidence as $entry) {
            $evidence[(string) $entry['signal']] = $entry['value'];
        }

        return [
            'label' => $row->info->name,
            'platform' => $row->info->provider,
            'verdict' => $row->assessment->verdict?->value,
            'evidence' => $evidence,
            'media' => ['state' => $row->info->media->state, 'url' => $row->info->media->url],
            'fatiguing' => $read->total,
        ];
    }

    /**
     * At most three, each a thing to do with the figure it rests on. No figure, no recommendation.
     *
     * @param  array<string, mixed>  $digest
     * @return list<array<string, mixed>>
     */
    private function recommendations(array $digest): array
    {
        $out = [];
        $creative = $digest['creative'];

        if (is_array($creative) && is_array($creative['evidence']) && array_key_exists('ctr_change', $creative['evidence'])) {
            $out[] = [
                'action' => 'refresh_creative',
                'subject' => $creative['label'],
                'evidence' => ['ctr_change' => $creative['evidence']['ctr_change'], 'frequency_change' => $creative['evidence']['frequency_change'] ?? null],
            ];
        }

        $weakest = $digest['weakest_platform'];
        $best = $digest['best_platform'];
        $by = $digest['ranked_by'];

        if (is_array($weakest) && is_array($best) && is_string($by) && $weakest['label'] !== $best['label']) {
            $key = $this->rankedKey($by);
            $out[] = [
                'action' => 'review_platform',
                'subject' => $weakest['label'],
                'evidence' => ['metric' => $by, 'value' => $weakest[$key]['value'] ?? null, 'best' => $best['label'], 'best_value' => $best[$key]['value'] ?? null],
            ];
        }

        $budget = $digest['budget'];

        if (is_array($budget) && is_array($budget['worst'])) {
            $out[] = [
                'action' => 'review_budget',
                'subject' => $budget['worst']['label'],
                'evidence' => ['risk' => $budget['worst']['risk'], 'utilisation' => $budget['worst']['utilisation']],
            ];
        }

        return array_slice($out, 0, self::RECOMMENDATIONS);
    }

    private function rankedKey(string $metric): string
    {
        return match ($metric) {
            Metric::CostPerConversion->value => 'cpa',
            Metric::Conversions->value => 'results',
            default => $metric,
        };
    }

    /**
     * The digest's own severity: the worst thing in it, decided by the queue's counts, never by a feeling.
     *
     * @param  array<string, mixed>  $digest
     */
    private function severity(array $digest): NotificationSeverity
    {
        $bySeverity = is_array($digest['by_severity']) ? $digest['by_severity'] : [];
        $budget = is_array($digest['budget']) ? $digest['budget'] : [];

        if (($bySeverity['critical'] ?? 0) > 0) {
            return NotificationSeverity::Critical;
        }

        if (($bySeverity['needs_attention'] ?? 0) > 0 || ($budget['at_risk'] ?? 0) > 0) {
            return NotificationSeverity::Attention;
        }

        return NotificationSeverity::Info;
    }
}
