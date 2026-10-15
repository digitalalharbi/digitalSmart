<?php

declare(strict_types=1);

namespace App\Application\Benchmark\Query;

use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\DTO\CampaignControlRow;
use App\Application\Advertising\Query\GetCampaignControl;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Advertising\CampaignObjective;
use Illuminate\Support\Facades\DB;

/**
 * How campaigns perform *relative to each other* across the agency — internal, and suppressed when thin.
 *
 * Two rules decide everything here:
 *
 * **A group needs at least three clients before it says anything.** Median CPL "for hospitality on Meta"
 * computed from one client is that client's CPL with a label on it, and the agency would be handing a
 * colleague — or, through a careless export, a competitor — one client's confidential figure. Below the
 * threshold the group is reported as *suppressed*, with how many it has, and carries no numbers.
 *
 * **Cost is never compared across currencies.** A median of riyals and dollars is a number with no unit, so
 * cost groups are keyed by currency as well. ROAS is unitless and groups freely.
 *
 * Needs `clients.view` as well as analytics: portal users have neither, and benchmarks are an agency-internal
 * instrument by definition — a client reading how they compare to the agency's other clients is the case
 * these rules exist to prevent.
 */
final readonly class GetBenchmarks
{
    public const int MINIMUM_CLIENTS = 3;

    public function __construct(
        private GetCampaignControl $campaigns,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::ClientsView);
        $this->guard->ensure(Permission::AnalyticsView);

        $assessment = $this->campaigns->assessAll(new CampaignControlFilter);
        /** @var list<CampaignControlRow> $rows */
        $rows = $assessment['rows'];

        /** @var array<string, string> $industry */
        $industry = [];

        foreach (DB::table('clients')->where('workspace_id', $this->actor->workspaceId()->value)->get(['id', 'industry']) as $c) {
            $industry[(string) $c->id] = is_string($c->industry) && $c->industry !== '' ? $c->industry : 'Unspecified';
        }

        $groups = [];

        foreach ($rows as $row) {
            if ($row->clientId === null || ! $this->isMeasured($row)) {
                continue;
            }

            foreach ($this->metrics($row) as $metric => $value) {
                foreach ([
                    'platform' => $row->provider,
                    'industry' => $industry[$row->clientId] ?? 'Unspecified',
                    'objective' => $row->objective->value,
                ] as $dimension => $key) {
                    $unit = $metric === 'roas' ? '' : ($row->currency ?? '');
                    $id = implode('|', [$metric, $dimension, $key, $unit]);
                    $groups[$id] ??= ['metric' => $metric, 'dimension' => $dimension, 'key' => $key, 'currency' => $unit === '' ? null : $unit, 'values' => [], 'clients' => []];
                    $groups[$id]['values'][] = $value;
                    $groups[$id]['clients'][$row->clientId] = true;
                }
            }
        }

        $out = [];

        foreach ($groups as $g) {
            $clients = count($g['clients']);
            $entry = [
                'metric' => $g['metric'],
                'dimension' => $g['dimension'],
                'key' => $g['key'],
                'currency' => $g['currency'],
                'campaigns' => count($g['values']),
                'clients' => $clients,
                'suppressed' => $clients < self::MINIMUM_CLIENTS,
            ];

            if (! $entry['suppressed']) {
                sort($g['values']);
                $entry += [
                    'p25' => $this->percentile($g['values'], 0.25),
                    'median' => $this->percentile($g['values'], 0.5),
                    'p75' => $this->percentile($g['values'], 0.75),
                ];
            }

            $out[] = $entry;
        }

        usort($out, static fn (array $a, array $b): int => [$a['metric'], $a['dimension'], $a['key']] <=> [$b['metric'], $b['dimension'], $b['key']]);

        return [
            'groups' => $out,
            'minimum_clients' => self::MINIMUM_CLIENTS,
            'campaigns_assessed' => count($rows),
            'window' => ['from' => $assessment['from']->format('Y-m-d'), 'to' => $assessment['to']->format('Y-m-d')],
        ];
    }

    /** A campaign with too little data has no performance to compare. */
    private function isMeasured(CampaignControlRow $row): bool
    {
        return $row->reportedDays >= 7 && $row->health->value !== 'inactive';
    }

    /** @return array<string, float> */
    private function metrics(CampaignControlRow $row): array
    {
        $metrics = [];

        if ($row->costPerResultMinor !== null) {
            $metric = match ($row->objective) {
                CampaignObjective::Leads => 'cost_per_lead',
                CampaignObjective::Sales, CampaignObjective::StoreTraffic => 'cost_per_acquisition',
                default => null,
            };

            if ($metric !== null) {
                $metrics[$metric] = $row->costPerResultMinor;
            }
        }

        if ($row->roas !== null) {
            $metrics['roas'] = $row->roas;
        }

        return $metrics;
    }

    /** @param list<float> $sorted */
    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        $rank = $p * ($n - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);

        return round($sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($rank - $lower), 2);
    }
}
