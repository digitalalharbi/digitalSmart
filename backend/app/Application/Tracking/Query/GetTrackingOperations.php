<?php

declare(strict_types=1);

namespace App\Application\Tracking\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Leads\Automation\LeadAutomationRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\FunnelRepository;
use App\Application\Tracking\Contract\TrackingAnalyticsReadModel;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\Contract\TrackingOperationsReadModel;
use App\Application\Tracking\DTO\TrackingLinkView;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Application\Tracking\Service\LinkPerformanceCalculator;
use App\Domain\Access\Permission;
use App\Domain\Tracking\Cost\LinkSpend;
use App\Domain\Tracking\Funnel\FunnelCalculator;
use App\Domain\Tracking\Funnel\FunnelDefinition;
use App\Domain\Tracking\Operations\AttentionRules;
use DateTimeImmutable;

/**
 * Are marketing and follow-up working together? One project, one period, the links the filters leave.
 *
 * Nothing here is invented. Spend is credited to a link only as the cost model allows (direct, or allocated when asked), and
 * every cost figure is computed over the links that have spend and over those alone: a cost per lead whose spend came from some
 * links and whose leads came from all of them would be a number about nothing. Counts that do not need spend are reported over
 * every link. Revenue sources stay apart. The team section is business-process events only — counts and medians of leads and
 * the time to first try — and nothing about how anybody works.
 */
final readonly class GetTrackingOperations
{
    /** More links than this and the view says it read the busiest and stopped, rather than quietly taking a minute. */
    public const int MAX_LINKS = 60;

    public function __construct(
        private TrackingLinkRepository $links,
        private LinkPerformanceCalculator $performance,
        private TrackingAnalyticsReadModel $analytics,
        private TrackingOperationsReadModel $operations,
        private FunnelRepository $funnels,
        private LeadAutomationRepository $automation,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    /**
     * @param  array{platform?: ?string, campaign_id?: ?string, ad_group_id?: ?string, ad_id?: ?string, link_id?: ?string, funnel_id?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function __invoke(string $projectId, ?DateTimeImmutable $from, ?DateTimeImmutable $to, array $filters, bool $allocateByClicks): array
    {
        $this->guard->ensure(Permission::TrackingView);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        if ($this->links->clientOfProject($workspaceId, $visible, $projectId) === null) {
            throw ResourceNotFound::of('Project');
        }

        $end = ($to ?? $this->clock->now())->setTime(0, 0)->modify('+1 day');
        $start = ($from ?? $end->modify('-30 days'))->setTime(0, 0);

        if ($start >= $end || $start < $end->modify('-400 days')) {
            throw new TrackingRefused('Choose a period of between one day and 400 days.');
        }

        $all = $this->links->list($workspaceId, $visible, $projectId, null, null);
        $chosen = array_values(array_filter($all, static fn (TrackingLinkView $l): bool => self::matches($l, $filters)));
        $truncated = count($chosen) > self::MAX_LINKS;
        $chosen = array_slice($chosen, 0, self::MAX_LINKS);
        $narrowed = $chosen !== [] && count($chosen) !== count($all) || ($filters['link_id'] ?? null) !== null;

        $sla = $this->automation->settings($workspaceId, $projectId)->slaMinutes;
        $zone = $this->automation->timezone($workspaceId);

        $rows = [];

        foreach ($chosen as $link) {
            $rows[] = ['link' => $link, 'p' => ($this->performance)($link, $workspaceId, $start, $end, $allocateByClicks)];
        }

        $byLink = $this->operations->responseByLink($workspaceId, $projectId, $start, $end, $sla);
        $response = $this->operations->response($workspaceId, $projectId, $start, $end, $sla);

        $table = [];
        $facts = [];

        foreach ($rows as $row) {
            [$line, $fact] = $this->line($row['link'], $row['p'], $byLink[$row['link']->id] ?? null, $sla);
            $table[] = $line;
            $facts[] = $fact;
        }

        $team = [
            'sla_minutes' => $sla, 'leads' => $response['leads'], 'attempted_within_sla' => $response['met_sla'],
            'overdue' => (int) ($response['overdue'] ?? 0), 'median_attempt_seconds' => $response['median_attempt_seconds'],
        ];

        $entities = [];

        foreach ($chosen as $link) {
            $level = $link->adId !== null ? 'ad' : ($link->adGroupId !== null ? 'ad_group' : ($link->campaignId !== null ? 'campaign' : null));
            $id = $link->adId ?? $link->adGroupId ?? $link->campaignId;

            if ($level !== null && $id !== null) {
                $entities[$level.':'.$id] = ['level' => $level, 'id' => $id];
            }
        }

        $funnelStages = $this->heroStages($workspaceId, $projectId, $filters['funnel_id'] ?? null, $start, $end, $narrowed, $rows);

        return [
            'from' => $start->format('Y-m-d'),
            'to' => $end->modify('-1 day')->format('Y-m-d'),
            'timezone' => $zone,
            'sla_minutes' => $sla,
            'links_considered' => count($chosen),
            'links_total' => count($all),
            'truncated' => $truncated,
            'kpis' => $this->kpis($rows, $response, $sla),
            'funnel' => $funnelStages,
            'links' => $table,
            'team' => array_map(fn (array $r): array => $this->teamRow($r, $sla), $this->operations->responseByOwner($workspaceId, $projectId, $start, $end, $sla)),
            'attribution' => $this->attribution($workspaceId, $projectId, $start, $end, array_values($entities), $rows),
            'attention' => AttentionRules::evaluate($facts, $team),
        ];
    }

    /** @param array{platform?: ?string, campaign_id?: ?string, ad_group_id?: ?string, ad_id?: ?string, link_id?: ?string, funnel_id?: ?string} $f */
    private static function matches(TrackingLinkView $l, array $f): bool
    {
        $ok = static fn (?string $wanted, ?string $actual): bool => $wanted === null || $wanted === $actual;

        return $ok($f['platform'] ?? null, $l->platform)
            && $ok($f['campaign_id'] ?? null, $l->campaignId)
            && $ok($f['ad_group_id'] ?? null, $l->adGroupId)
            && $ok($f['ad_id'] ?? null, $l->adId)
            && $ok($f['link_id'] ?? null, $l->id);
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>|null  $slaRow
     * @return array{0: array<string, mixed>, 1: array{id: string, name: string, clicks: int, landing_views: ?int, leads: int, qualified: int, appointments: int, sales: int, spend_minor: ?int, spend_basis: string, shared_spend_minor: ?int, cpl_minor: ?int, landing_reported: bool}}
     */
    private function line(TrackingLinkView $link, array $p, ?array $slaRow, ?int $sla): array
    {
        /** @var LinkSpend $spend */
        $spend = $p['spend'];
        /** @var array<string, int|null> $c */
        $c = $p['counts'];
        $costs = $p['costs'];
        $clicks = (int) ($c['link_click'] ?? 0);
        $landing = $c['landing_view'] ?? null;
        $leads = (int) ($c['lead_created'] ?? 0);

        $line = [
            'link' => ['id' => $link->id, 'name' => $link->name],
            'platform' => $link->platform,
            'campaign' => $link->campaign,
            'ad_group' => $link->adGroup,
            'ad' => $link->ad,
            'bound' => $link->campaignId !== null,
            'spend' => ['basis' => $spend->basis->value, 'minor' => $spend->minor, 'currency' => $spend->currency, 'reason' => $spend->reason, 'level' => $spend->level],
            'clicks' => $clicks,
            'leads' => $leads,
            'qualified' => $c['lead_qualified'] ?? null,
            'appointments' => $c['appointment_booked'] ?? null,
            'sales' => $c['sale_completed'] ?? null,
            'cpl' => $costs['cpl'],
            'cpql' => $costs['cpql'],
            'cac' => $costs['cac'],
            'revenue' => $p['revenue'],
            'sla' => $slaRow === null || $sla === null || $slaRow['leads'] === 0 ? null : ['leads' => $slaRow['leads'], 'met' => $slaRow['met_sla'], 'overdue' => $slaRow['overdue']],
            /* A page that does not report is a different fact from a quiet link. */
            'tracking' => $clicks === 0 ? 'no_clicks' : ($landing === null ? 'page_not_reporting' : 'ok'),
        ];

        $fact = [
            'id' => $link->id, 'name' => $link->name, 'clicks' => $clicks, 'landing_views' => $landing === null ? null : (int) $landing, 'leads' => $leads,
            'qualified' => (int) ($c['lead_qualified'] ?? 0), 'appointments' => (int) ($c['appointment_booked'] ?? 0), 'sales' => (int) ($c['sale_completed'] ?? 0),
            'spend_minor' => $spend->minor, 'spend_basis' => $spend->basis->value, 'shared_spend_minor' => $spend->sharedMinor,
            'cpl_minor' => is_int($costs['cpl']['minor']) ? $costs['cpl']['minor'] : null, 'landing_reported' => $clicks === 0 || $landing !== null,
        ];

        return [$line, $fact];
    }

    /**
     * @param  list<array{link: TrackingLinkView, p: array<string, mixed>}>  $rows
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function kpis(array $rows, array $response, ?int $sla): array
    {
        $tracked = ['clicks' => 0, 'leads' => 0, 'qualified' => 0, 'appointments' => 0, 'sales' => 0];
        /** @var array<string, array{spend: int, links: int, leads: int, qualified: int, appointments: int, sales: int, lead_value: int, store: int}> $by */
        $by = [];
        $unpriced = 0;

        foreach ($rows as $row) {
            /** @var LinkSpend $spend */
            $spend = $row['p']['spend'];
            /** @var array<string, int|null> $c */
            $c = $row['p']['counts'];
            $tracked['clicks'] += (int) ($c['link_click'] ?? 0);
            $tracked['leads'] += (int) ($c['lead_created'] ?? 0);
            $tracked['qualified'] += (int) ($c['lead_qualified'] ?? 0);
            $tracked['appointments'] += (int) ($c['appointment_booked'] ?? 0);
            $tracked['sales'] += (int) ($c['sale_completed'] ?? 0);

            if (! $spend->basis->hasSpend() || $spend->minor === null || $spend->currency === null) {
                $unpriced++;

                continue;
            }

            $g = &$by[$spend->currency];
            $g ??= ['spend' => 0, 'links' => 0, 'leads' => 0, 'qualified' => 0, 'appointments' => 0, 'sales' => 0, 'lead_value' => 0, 'store' => 0];
            $g['spend'] += $spend->minor;
            $g['links']++;
            $g['leads'] += (int) ($c['lead_created'] ?? 0);
            $g['qualified'] += (int) ($c['lead_qualified'] ?? 0);
            $g['appointments'] += (int) ($c['appointment_booked'] ?? 0);
            $g['sales'] += (int) ($c['sale_completed'] ?? 0);

            foreach (['lead_value' => 'lead_value', 'store_attributed' => 'store'] as $source => $key) {
                $rev = $row['p']['revenue'][$source] ?? null;

                if (is_array($rev) && $rev['minor'] !== null && $rev['currency'] === $spend->currency) {
                    $g[$key] += (int) $rev['minor'];
                }
            }

            unset($g);
        }

        $div = static fn (int $spend, int $n): ?int => $n === 0 ? null : intdiv($spend + intdiv($n, 2), $n);
        $currencies = [];

        foreach ($by as $currency => $g) {
            $currencies[] = [
                'currency' => (string) $currency, 'spend_minor' => $g['spend'], 'priced_links' => $g['links'],
                'leads' => $g['leads'], 'qualified' => $g['qualified'], 'appointments' => $g['appointments'], 'sales' => $g['sales'],
                'cpl_minor' => $div($g['spend'], $g['leads']), 'cpql_minor' => $div($g['spend'], $g['qualified']), 'cac_minor' => $div($g['spend'], $g['sales']),
                'revenue' => [
                    'lead_value_minor' => $g['lead_value'] > 0 ? $g['lead_value'] : null,
                    'store_attributed_minor' => $g['store'] > 0 ? $g['store'] : null,
                ],
                'roas' => [
                    'lead_value' => $g['lead_value'] > 0 && $g['spend'] > 0 ? round($g['lead_value'] / $g['spend'], 2) : null,
                    'store_attributed' => $g['store'] > 0 && $g['spend'] > 0 ? round($g['store'] / $g['spend'], 2) : null,
                ],
            ];
        }

        return [
            'tracked' => $tracked,
            'by_currency' => $currencies,
            'unpriced_links' => $unpriced,
            'response' => [
                'sla_minutes' => $sla,
                'leads' => $response['leads'],
                'median_attempt_seconds' => $response['median_attempt_seconds'],
                'median_contact_seconds' => $response['median_contact_seconds'],
                /* Over leads that came: a lead nobody has tried yet is not a success, and not yet a miss either. */
                'sla_compliance' => $sla === null || $response['leads'] === 0 || $response['met_sla'] === null ? null : round($response['met_sla'] / $response['leads'], 4),
                'overdue' => $response['overdue'],
            ],
        ];
    }

    /**
     * @param  list<array{link: TrackingLinkView, p: array<string, mixed>}>  $rows
     * @return array<string, mixed>
     */
    private function heroStages(string $workspaceId, string $projectId, ?string $funnelId, DateTimeImmutable $start, DateTimeImmutable $end, bool $narrowed, array $rows): array
    {
        $names = ['link_click', 'landing_view', 'lead_created', 'lead_contacted', 'lead_qualified', 'appointment_booked', 'sale_completed'];
        $stages = FunnelDefinition::stagesFrom(array_map(static fn (string $s): array => ['source' => $s], $names));

        if ($funnelId !== null) {
            $stored = $this->funnels->find($workspaceId, $this->visibility->visibleProjectIds(), $funnelId) ?? throw ResourceNotFound::of('Funnel');

            if ($stored['project_id'] !== $projectId) {
                throw ResourceNotFound::of('Funnel');
            }

            $stages = $stored['stages'];
        }

        if (! $narrowed) {
            return ['scope' => 'project', ...FunnelCalculator::compute($stages, $this->analytics->cohort($workspaceId, $projectId, null, $start, $end))];
        }

        /*
         * A subset of links: the visits of different links are different visits, so counts add; times between steps do not
         * (a median of medians is not a median), and are left out rather than faked.
         */
        $sum = array_fill_keys($names, 0);
        $nullSeen = array_fill_keys($names, false);

        foreach ($rows as $row) {
            foreach ($names as $name) {
                $v = $row['p']['counts'][$name] ?? null;

                if ($v === null) {
                    $nullSeen[$name] = true;
                } else {
                    $sum[$name] += (int) $v;
                }
            }
        }

        $out = [];
        $previous = null;

        foreach ($names as $i => $name) {
            $count = $nullSeen[$name] ? null : $sum[$name];
            $rate = $i === 0 || $count === null || $previous === null || $previous === 0 || $count > $previous ? null : round($count / $previous, 4);
            $out[] = [
                'source' => $name, 'count' => $count, 'conversion_from_previous' => $rate,
                'drop_off' => $rate === null || $previous === null || $count === null ? null : $previous - $count,
                'median_seconds_from_previous' => null,
                'reason' => $count === null ? 'A destination page in the chosen links has not reported this step.' : null,
            ];
            $previous = $count;
        }

        return ['scope' => 'chosen_links', 'stages' => $out];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function teamRow(array $r, ?int $sla): array
    {
        return [
            'owner' => $r['owner_id'] === null ? null : ['id' => $r['owner_id'], 'name' => $r['owner_name']],
            'assigned' => $r['leads'], 'contacted' => $r['contacted'], 'qualified' => $r['qualified'],
            'median_attempt_seconds' => $r['median_attempt_seconds'], 'median_contact_seconds' => $r['median_contact_seconds'],
            'sla_compliance' => $sla === null || $r['leads'] === 0 || $r['met_sla'] === null ? null : round($r['met_sla'] / $r['leads'], 4),
            'overdue' => $r['overdue'],
        ];
    }

    /**
     * Five readings of one pipeline, side by side and never added together.
     *
     * @param  list<array{level: string, id: string}>  $entities
     * @param  list<array{link: TrackingLinkView, p: array<string, mixed>}>  $rows
     * @return array<string, mixed>
     */
    private function attribution(string $workspaceId, string $projectId, DateTimeImmutable $start, DateTimeImmutable $end, array $entities, array $rows): array
    {
        $touches = $this->operations->touches($workspaceId, $projectId, $start, $end);
        $reported = $this->operations->platformReported($workspaceId, $entities, $start, $end);
        $attributed = 0;
        $won = 0;
        $currencyLead = [];

        foreach ($rows as $row) {
            $attributed += (int) ($row['p']['counts']['lead_created'] ?? 0);
            $won += (int) ($row['p']['counts']['sale_completed'] ?? 0);
            $rev = $row['p']['revenue']['lead_value'] ?? null;

            if (is_array($rev) && $rev['minor'] !== null && is_string($rev['currency'])) {
                $currencyLead[$rev['currency']] = ($currencyLead[$rev['currency']] ?? 0) + (int) $rev['minor'];
            }
        }

        $coverage = $touches['leads'] === 0 ? null : round($touches['tracked'] / $touches['leads'], 4);

        return [
            'platform_reported' => $reported + ['note' => 'What the advertising platforms say about the entities these links are bound to. It is their count, not this product’s.'],
            'first_known_touch' => ['leads_with_a_known_first_touch' => $touches['tracked'] - $touches['first_touch_unknown'], 'same_as_last' => $touches['same_touch'], 'different_from_last' => $touches['different_touch']],
            'last_known_touch' => ['leads' => $touches['tracked']],
            'digitalsmart_attributed' => ['leads' => $attributed],
            'outcome' => ['sales' => $won, 'recorded_won_value_by_currency' => $currencyLead],
            'coverage' => $coverage,
            'confidence' => match (true) {
                $coverage === null => 'none',
                $coverage >= 0.8 => 'high',
                $coverage >= 0.5 => 'medium',
                default => 'low',
            },
            'missing_evidence' => $touches['leads'] - $touches['tracked'],
        ];
    }
}
