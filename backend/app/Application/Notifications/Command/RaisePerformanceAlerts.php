<?php

declare(strict_types=1);

namespace App\Application\Notifications\Command;

use App\Application\Analytics\Contract\AnalyticsReadModel;
use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Analytics\DTO\BreakdownRow;
use App\Application\Analytics\Service\CreativeIntelligenceReader;
use App\Application\Attention\Query\GetAttentionQueue;
use App\Application\Digest\Contract\DigestStore;
use App\Application\Notifications\Contract\AlertSubjects;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\Permission;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Attention\AttentionStream;
use App\Domain\Identity\UserId;
use App\Domain\Notifications\AlertRule;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSeverity;
use App\Domain\Shared\ValueObject\DateRange;
use App\Domain\Workspace\WorkspaceId;
use Throwable;

/**
 * Turn the product's own signals into the bell's ten alerts, once each.
 *
 * Four sources, none of them new: the attention queue (campaign health, budget risk, tracking issues) read as an
 * owner so nothing is missed; the analytics layer's own figures for each campaign against the week before (ROAS
 * drop, spend spike); the creative layer's verdict (fatigue); and a campaign whose end date passed in the last day.
 * Each becomes a draft with a dedupe key, so `Announce` says it once per cooldown and again only when it got worse
 * — and the recipient resolver, not this command, decides who may be told.
 */
final readonly class RaisePerformanceAlerts
{
    private const int WINDOW_DAYS = 7;

    private const int CAMPAIGNS = 200;

    /** @var list<Metric> */
    private const array METRICS = [Metric::Spend, Metric::Roas, Metric::Conversions, Metric::CostPerConversion];

    public function __construct(
        private Announce $announce,
        private DigestStore $workspaces,
        private AlertSubjects $subjects,
        private GetAttentionQueue $queue,
        private AnalyticsReadModel $analytics,
        private CreativeIntelligenceReader $creatives,
        private ActorContextBinder $actors,
        private Clock $clock,
    ) {}

    /** @return array{raised: int, workspaces: int, failed: int} */
    public function __invoke(?string $workspaceId = null): array
    {
        $raised = $failed = $count = 0;

        foreach ($this->workspaces->workspaces($workspaceId) as $workspace) {
            $id = $workspace['workspace_id'];
            $owner = $this->subjects->ownerOf($id);

            if ($owner === null) {
                continue;
            }

            $count++;

            try {
                $this->actors->bind(new UserId($owner), new WorkspaceId($id));

                foreach ($this->drafts($id) as $draft) {
                    $raised += count(($this->announce)($draft, $id)) > 0 ? 1 : 0;
                }
            } catch (Throwable $failure) {
                report($failure);
                $failed++;
            } finally {
                $this->actors->clear();
            }
        }

        return ['raised' => $raised, 'workspaces' => $count, 'failed' => $failed];
    }

    /** @return list<NotificationDraft> */
    private function drafts(string $workspaceId): array
    {
        return [
            ...$this->fromAttention(),
            ...$this->fromFigures($workspaceId),
            ...$this->fromCreatives($workspaceId),
            ...$this->fromEnded($workspaceId),
        ];
    }

    /** @return list<NotificationDraft> */
    private function fromAttention(): array
    {
        $drafts = [];
        $queue = ($this->queue)([AttentionStream::Campaigns, AttentionStream::Budgets, AttentionStream::Tracking]);

        foreach ($queue->items as $item) {
            $kind = AlertRule::forReason($item->reasonCode);
            $subjectId = $this->subjectIdOf($item->id);

            if ($kind === null || $subjectId === null) {
                continue;
            }

            $drafts[] = new NotificationDraft(
                kind: $kind,
                title: sprintf('%s — %s', $kind->label(), $item->entityLabel),
                subjectId: $subjectId,
                body: $item->message,
                aboutProjectId: $item->projectId,
                aboutClientId: $item->clientId,
                alsoRequires: $this->widerThanAProject($item->projectId, $item->clientId),
                severity: AlertRule::severityOf($item->severity),
                payload: ['client' => $item->clientName, 'project' => $item->projectName, 'reason' => $item->reasonCode, ...$item->reasonParams],
                dedupeKey: sprintf('%s:%s', $kind->value, $subjectId),
            );
        }

        return $drafts;
    }

    /** `campaign:{id}`, `budget:{id}:{risk}`, `tracking:{code}:{subject}` — the id is the second part, when it is one. */
    private function subjectIdOf(string $itemId): ?string
    {
        $parts = explode(':', $itemId);
        $id = $parts[1] ?? '';

        if (($parts[0] ?? '') === 'tracking') {
            $id = $parts[2] ?? '';
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1 ? $id : null;
    }

    /** @return list<NotificationDraft> */
    private function fromFigures(string $workspaceId): array
    {
        $today = $this->clock->now();
        $current = DateRange::lastDays(self::WINDOW_DAYS, $today);
        $previous = $current->previousPeriod();
        $filters = new AnalyticsFilters;

        $now = $this->byKey($this->analytics->breakdown($workspaceId, $current, $filters, Dimension::Campaign, self::METRICS, Metric::Spend, self::CAMPAIGNS));
        $before = $this->byKey($this->analytics->breakdown($workspaceId, $previous, $filters, Dimension::Campaign, self::METRICS, Metric::Spend, self::CAMPAIGNS));
        $about = $this->subjects->campaigns($workspaceId, array_keys($now));
        $drafts = [];

        foreach ($now as $key => $row) {
            $earlier = $before[$key] ?? null;
            $scope = $about[$key] ?? null;

            if ($earlier === null || $scope === null) {
                continue;
            }

            $roasNow = $this->float($row, Metric::Roas);
            $roasBefore = $this->float($earlier, Metric::Roas);
            $severity = AlertRule::roasDrop($roasBefore, $roasNow);

            if ($severity !== null) {
                $drafts[] = $this->figureDraft(NotificationKind::RoasDrop, $key, $scope, $severity, $roasBefore, $roasNow, $row);
            }

            $spendNow = $this->float($row, Metric::Spend);
            $spendBefore = $this->float($earlier, Metric::Spend);
            $spike = AlertRule::spendSpike($spendBefore, $spendNow);

            if ($spike !== null) {
                $drafts[] = $this->figureDraft(NotificationKind::SpendSpike, $key, $scope, $spike, $spendBefore, $spendNow, $row);
            }
        }

        return $drafts;
    }

    /**
     * @param  array{label: string, project_id: ?string, client_id: ?string}  $scope
     */
    private function figureDraft(NotificationKind $kind, string $campaignId, array $scope, NotificationSeverity $severity, ?float $before, ?float $after, BreakdownRow $row): NotificationDraft
    {
        $change = $before === null || $after === null || $before <= 0.0 ? null : round(($after - $before) / $before, 4);

        return new NotificationDraft(
            kind: $kind,
            title: sprintf('%s — %s', $kind->label(), $scope['label']),
            subjectId: $campaignId,
            aboutProjectId: $scope['project_id'],
            aboutClientId: $scope['client_id'],
            alsoRequires: $this->widerThanAProject($scope['project_id'], $scope['client_id']),
            severity: $severity,
            payload: [
                'before' => $before,
                'after' => $after,
                'change_ratio' => $change,
                'currency' => $row->metrics->currency,
                'days' => self::WINDOW_DAYS,
                'source' => $row->metrics->source->value,
            ],
            dedupeKey: sprintf('%s:%s', $kind->value, $campaignId),
        );
    }

    /** @return list<NotificationDraft> */
    private function fromCreatives(string $workspaceId): array
    {
        $today = $this->clock->now();
        $read = $this->creatives->read($workspaceId, DateRange::lastDays(self::WINDOW_DAYS, $today), new AnalyticsFilters, 'asset', null, 'fatiguing', 50);
        $about = $this->subjects->creatives($workspaceId, array_map(static fn ($row): string => $row->info->key, $read->rows));
        $drafts = [];

        foreach ($read->rows as $row) {
            $scope = $about[$row->info->key] ?? null;

            if ($scope === null) {
                continue;
            }

            $evidence = [];

            foreach ($row->assessment->evidence as $entry) {
                $evidence[(string) $entry['signal']] = $entry['value'];
            }

            $drafts[] = new NotificationDraft(
                kind: NotificationKind::CreativeFatigue,
                title: sprintf('%s — %s', NotificationKind::CreativeFatigue->label(), $row->info->name),
                subjectId: $row->info->key,
                aboutProjectId: $scope['project_id'],
                aboutClientId: $scope['client_id'],
                alsoRequires: $this->widerThanAProject($scope['project_id'], $scope['client_id']),
                /* A fatigue verdict is corroborated by construction; the click-through fall decides how loud. */
                severity: NotificationSeverity::forAdverseChange(-(float) ($evidence['ctr_change'] ?? 0)),
                payload: ['change_ratio' => $evidence['ctr_change'] ?? null, 'frequency_change' => $evidence['frequency_change'] ?? null, 'platform' => $row->info->provider, 'media' => $row->info->media->url],
                dedupeKey: sprintf('%s:%s', NotificationKind::CreativeFatigue->value, $row->info->key),
            );
        }

        return $drafts;
    }

    /** @return list<NotificationDraft> */
    private function fromEnded(string $workspaceId): array
    {
        $now = $this->clock->now();
        $drafts = [];

        foreach ($this->subjects->endedBetween($workspaceId, $now->modify('-1 day'), $now) as $campaign) {
            $drafts[] = new NotificationDraft(
                kind: NotificationKind::CampaignEnded,
                title: sprintf('%s — %s', NotificationKind::CampaignEnded->label(), $campaign['label']),
                subjectId: $campaign['id'],
                aboutProjectId: $campaign['project_id'],
                aboutClientId: $campaign['client_id'],
                alsoRequires: $this->widerThanAProject($campaign['project_id'], $campaign['client_id']),
                severity: NotificationSeverity::Info,
                payload: ['ended_at' => $campaign['ended_at']],
                dedupeKey: sprintf('%s:%s', NotificationKind::CampaignEnded->value, $campaign['id']),
                /* An ending is said once; a week is longer than any scheduler gap. */
                cooldownHours: 24 * 7,
            );
        }

        return $drafts;
    }

    /**
     * A thing bound to no project is readable only by the people who see every project, so an alert about it
     * must ask for that permission too. Without this, "about nothing in particular" would reach everybody with
     * the kind's own permission — a buyer on no project included, who could not open the campaign it names.
     */
    private function widerThanAProject(?string $projectId, ?string $clientId): ?string
    {
        return $projectId === null && $clientId === null ? Permission::ProjectsViewAll->value : null;
    }

    /**
     * @param  list<BreakdownRow>  $rows
     * @return array<string, BreakdownRow>
     */
    private function byKey(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[$row->key] = $row;
        }

        return $out;
    }

    private function float(BreakdownRow $row, Metric $metric): ?float
    {
        $value = $row->metrics->get($metric)?->value;

        return $value === null ? null : (float) $value;
    }
}
