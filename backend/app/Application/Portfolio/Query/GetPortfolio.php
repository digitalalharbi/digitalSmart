<?php

declare(strict_types=1);

namespace App\Application\Portfolio\Query;

use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\DTO\CampaignControlRow;
use App\Application\Advertising\Query\GetCampaignControl;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Budget\DTO\BudgetControlRow;
use App\Application\Budget\Query\GetBudgetControl;
use App\Application\Measurement\Query\GetTrackingHealth;
use App\Application\Portfolio\Contract\PortfolioReadModel;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Advertising\CampaignHealth;

/**
 * How the whole agency, one client, or one project is doing — assembled from the reads that already exist.
 *
 * It computes nothing of its own about a campaign, a budget or a tracking source. Campaign verdicts come
 * from `GetCampaignControl`, budget risks from `GetBudgetControl`, tracking from `GetTrackingHealth`; this
 * only groups them by client and project. A portfolio that re-derived health would be a third opinion
 * about a campaign, and the one a director reads first.
 *
 * Sections the reader may not see are `null`, not zero: a finance figure shown to a creative lead as 0
 * would be a statement, and "not shown to your role" is the true one.
 */
final readonly class GetPortfolio
{
    public function __construct(
        private GetCampaignControl $campaigns,
        private GetBudgetControl $budgets,
        private GetTrackingHealth $tracking,
        private PortfolioReadModel $outcomes,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(?string $clientId = null): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $permissions = $this->actor->permissions();
        $workspace = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        $filter = new CampaignControlFilter(clientIds: $clientId === null ? [] : [$clientId]);
        $assessment = $this->campaigns->assessAll($filter);
        $from = $assessment['from'];
        $to = $assessment['to'];
        /** @var list<CampaignControlRow> $rows */
        $rows = $assessment['rows'];

        $roster = $this->outcomes->roster($workspace, $visible, $clientId);
        $leads = $permissions->has(Permission::LeadsView) ? $this->outcomes->leads($workspace, $visible, $from, $to) : null;
        $sales = $this->outcomes->sales($workspace, $visible, $from, $to);
        $work = $this->outcomes->openWork($workspace, $visible);

        /*
         * One client's view carries that client's outcomes and nobody else's. The readers above are workspace-wide,
         * and summing them unfiltered put the whole agency's leads and store revenue on a single client's page.
         */
        if ($clientId !== null) {
            $only = [$clientId => true];
            $leads = $leads === null ? null : array_intersect_key($leads, $only);
            $sales = array_intersect_key($sales, $only);
        }

        $budgetRows = $permissions->has(Permission::BudgetsView) ? ($this->budgets)($clientId) : null;
        $trackingView = $permissions->has(Permission::TrackingView) ? ($this->tracking)($clientId) : null;

        $perClient = $this->groupCampaigns($rows, 'clientId');
        $perProject = $this->groupCampaigns($rows, 'projectId');

        $clients = [];

        foreach ($roster['clients'] as $client) {
            $group = $perClient[$client['id']] ?? $this->emptyGroup();

            $clients[] = [
                ...$client,
                ...$this->present($group),
                'leads' => $leads === null ? null : ($leads[$client['id']]['leads'] ?? 0),
                'qualified_leads' => $leads === null ? null : ($leads[$client['id']]['qualified'] ?? 0),
                'sales' => $sales[$client['id']]['orders'] ?? 0,
                'budget_risks' => $budgetRows === null ? null : $this->budgetRisks($budgetRows, $client['id']),
                'tracking_attention' => $trackingView === null ? null : $this->trackingAttention($trackingView->sources, $client['id']),
                'projects' => count(array_filter($roster['projects'], static fn (array $p): bool => $p['client_id'] === $client['id'])),
            ];
        }

        $projects = [];
        $clientNames = [];

        foreach ($roster['clients'] as $client) {
            $clientNames[$client['id']] = $client['name'];
        }

        foreach ($roster['projects'] as $project) {
            $group = $perProject[$project['id']] ?? $this->emptyGroup();
            $projects[] = [
                ...$project,
                /* Project names repeat across an agency — "Always-on" belongs to a dozen clients — so a tile says whose it is. */
                'client_name' => $clientNames[$project['client_id']] ?? null,
                ...$this->present($group),
                'open_work' => $work[$project['id']] ?? 0,
            ];
        }

        $all = $this->emptyGroup();

        foreach ($rows as $row) {
            $this->add($all, $row);
        }

        $health = array_fill_keys(CampaignHealth::values(), 0);

        foreach ($rows as $row) {
            $health[$row->health->value]++;
        }

        return [
            'window' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'kpis' => [
                ...$this->present($all),
                'leads' => $leads === null ? null : array_sum(array_column($leads, 'leads')),
                'qualified_leads' => $leads === null ? null : array_sum(array_column($leads, 'qualified')),
                'sales' => array_sum(array_column($sales, 'orders')),
                /* Store revenue is the store's own figure, never added to the platforms'. */
                'store_revenue' => $this->sumCurrencies(array_column($sales, 'revenue')),
                'active_clients' => count(array_filter($roster['clients'], static fn (array $c): bool => $c['status'] === 'active')),
                'active_projects' => count(array_filter($roster['projects'], static fn (array $p): bool => $p['status'] === 'active')),
            ],
            'health' => $health,
            'clients' => $clients,
            'projects' => $clientId === null ? $this->worstFirst($projects, 60) : $projects,
            'platform_mix' => $this->platformMix($rows),
            'budget_health' => $budgetRows === null ? null : [
                'approved' => count($budgetRows),
                'at_risk' => count(array_filter($budgetRows, static fn (BudgetControlRow $b): bool => $b->risks !== [])),
            ],
            'tracking_health' => $trackingView === null ? null : [
                'sources' => count($trackingView->sources),
                'needing_attention' => count(array_filter(
                    $trackingView->sources,
                    static fn ($s): bool => $s->freshness->needsAttention(),
                )),
                'critical' => $trackingView->criticalCount(),
            ],
            'assessed' => count($rows),
            'total_campaigns' => $assessment['total'],
            'is_truncated' => count($rows) < $assessment['total'],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyGroup(): array
    {
        return ['spend' => [], 'revenue' => [], 'roas_spend' => [], 'active' => 0, 'health' => array_fill_keys(CampaignHealth::values(), 0), 'campaigns' => 0];
    }

    /** @param array<string, mixed> $g */
    private function add(array &$g, CampaignControlRow $row): void
    {
        $g['campaigns']++;
        $g['health'][$row->health->value]++;
        $g['active'] += $row->status->isSpending() ? 1 : 0;

        if ($row->currency !== null && $row->spendMinor !== null) {
            $g['spend'][$row->currency] = ($g['spend'][$row->currency] ?? 0) + $row->spendMinor;

            /* ROAS is revenue over the spend *of campaigns that measure revenue*, in one currency. */
            if ($row->revenueMinor !== null) {
                $g['revenue'][$row->currency] = ($g['revenue'][$row->currency] ?? 0) + $row->revenueMinor;
                $g['roas_spend'][$row->currency] = ($g['roas_spend'][$row->currency] ?? 0) + $row->spendMinor;
            }
        }
    }

    /**
     * @param  list<CampaignControlRow>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function groupCampaigns(array $rows, string $by): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $key = $by === 'clientId' ? $row->clientId : $row->projectId;

            if ($key === null) {
                continue;
            }

            $groups[$key] ??= $this->emptyGroup();
            $this->add($groups[$key], $row);
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $g
     * @return array<string, mixed>
     */
    private function present(array $g): array
    {
        $roas = [];

        foreach ($g['roas_spend'] as $currency => $spend) {
            if ($spend > 0) {
                $roas[] = ['currency' => $currency, 'value' => round(($g['revenue'][$currency] ?? 0) / $spend, 2)];
            }
        }

        return [
            'spend' => $this->currencies($g['spend']),
            'platform_revenue' => $this->currencies($g['revenue']),
            'roas' => $roas,
            'active_campaigns' => $g['active'],
            'campaigns' => $g['campaigns'],
            'campaign_health' => $g['health'],
            'needs_attention' => $g['health']['needs_attention'] + $g['health']['critical'],
        ];
    }

    /**
     * @param  array<string, int>  $byCurrency
     * @return list<array{currency: string, minor: int}>
     */
    private function currencies(array $byCurrency): array
    {
        ksort($byCurrency);

        return array_map(
            static fn (string $c, int $m): array => ['currency' => $c, 'minor' => $m],
            array_keys($byCurrency),
            array_values($byCurrency),
        );
    }

    /**
     * @param  list<array<string, int>>  $sets
     * @return list<array{currency: string, minor: int}>
     */
    private function sumCurrencies(array $sets): array
    {
        $total = [];

        foreach ($sets as $set) {
            foreach ($set as $currency => $minor) {
                $total[$currency] = ($total[$currency] ?? 0) + $minor;
            }
        }

        return $this->currencies($total);
    }

    /**
     * @param  list<CampaignControlRow>  $rows
     * @return list<array<string, mixed>>
     */
    private function platformMix(array $rows): array
    {
        $mix = [];

        foreach ($rows as $row) {
            if ($row->currency === null || $row->spendMinor === null) {
                continue;
            }

            $mix[$row->provider][$row->currency] = ($mix[$row->provider][$row->currency] ?? 0) + $row->spendMinor;
        }

        ksort($mix);

        return array_map(
            fn (string $provider, array $byCurrency): array => ['provider' => $provider, 'spend' => $this->currencies($byCurrency)],
            array_keys($mix),
            array_values($mix),
        );
    }

    /** @param list<BudgetControlRow> $rows */
    private function budgetRisks(array $rows, string $clientId): int
    {
        return count(array_filter(
            $rows,
            static fn (BudgetControlRow $b): bool => $b->clientId === $clientId && $b->risks !== [],
        ));
    }

    /** @param list<\App\Application\Measurement\DTO\MeasurementSourceView> $sources */
    private function trackingAttention(array $sources, string $clientId): int
    {
        return count(array_filter(
            $sources,
            static fn ($s): bool => $s->clientId === $clientId && $s->freshness->needsAttention(),
        ));
    }

    /**
     * The agency view carries the projects that most need a look, not all of them — a hundred and fifty tiles is
     * not a clearer picture than the sixty with something wrong, and the payload stays bounded as the agency grows.
     * A client's own page carries every project it has.
     *
     * @param  list<array<string, mixed>>  $projects
     * @return list<array<string, mixed>>
     */
    private function worstFirst(array $projects, int $limit): array
    {
        usort($projects, static function (array $a, array $b): int {
            $ha = is_array($a['campaign_health'] ?? null) ? $a['campaign_health'] : [];
            $hb = is_array($b['campaign_health'] ?? null) ? $b['campaign_health'] : [];

            return [-(int) ($ha['critical'] ?? 0), -(int) ($ha['needs_attention'] ?? 0), -(int) ($ha['watch'] ?? 0), (string) $a['name']]
                <=> [-(int) ($hb['critical'] ?? 0), -(int) ($hb['needs_attention'] ?? 0), -(int) ($hb['watch'] ?? 0), (string) $b['name']];
        });

        return array_slice($projects, 0, $limit);
    }
}
