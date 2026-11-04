<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Measurement\Contract\Ga4TrafficReadModel;
use App\Application\Measurement\DTO\Ga4TrafficQuery;
use App\Application\Measurement\DTO\Ga4TrafficView;
use App\Domain\Advertising\MetricSource;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads `ga4_traffic_daily` for exactly the sources it is handed.
 *
 * Sums are SQL sums, which are null when every addend is null — so a metric the property never reported stays null
 * instead of becoming 0. Rates are computed here from two sums only when both exist and the denominator is positive.
 * Users are not selected at all (see `Ga4TrafficView`).
 */
final readonly class EloquentGa4TrafficReadModel implements Ga4TrafficReadModel
{
    private const array SORTS = [
        'sessions' => 'sessions',
        'engaged_sessions' => 'engaged_sessions',
        'page_views' => 'page_views',
    ];

    public function read(string $workspaceId, array $sources, Ga4TrafficQuery $query): Ga4TrafficView
    {
        $ids = array_map(static fn ($source): string => $source->id, $sources);

        $days = (int) $query->from->diff($query->until)->days + 1;
        $previousUntil = $query->from->modify('-1 day');
        $previousFrom = $previousUntil->modify('-'.($days - 1).' days');

        $totals = $this->figures($this->base($workspaceId, $ids, $query->from, $query->until));
        $previous = $this->figures($this->base($workspaceId, $ids, $previousFrom, $previousUntil));

        $grouped = $query->group === 'source_medium'
            ? ['source_name', 'medium']
            : ['channel_group'];

        $sort = self::SORTS[$query->sort] ?? 'sessions';
        $direction = $query->direction === 'asc' ? 'asc' : 'desc';
        $perPage = in_array($query->perPage, [10, 25, 50], true) ? $query->perPage : 10;
        $page = max(1, $query->page);

        $rowCount = (int) DB::query()
            ->fromSub($this->base($workspaceId, $ids, $query->from, $query->until)->select($grouped)->groupBy($grouped), 'g')
            ->count();

        $rows = [];
        $sessionsTotal = $totals['sessions'];

        foreach (
            $this->base($workspaceId, $ids, $query->from, $query->until)
                ->select($grouped)
                ->selectRaw($this->sums())
                ->groupBy($grouped)
                ->orderByRaw($sort.' '.$direction.' nulls last')
                ->orderBy($grouped[0])
                ->offset(($page - 1) * $perPage)->limit($perPage)
                ->get() as $row
        ) {
            $figures = $this->shape((array) $row);
            $label = $query->group === 'source_medium'
                ? ((string) $row->source_name).' / '.((string) $row->medium)
                : (string) $row->channel_group;

            $rows[] = [
                'name' => $label,
                'channel_group' => $query->group === 'source_medium' ? null : (string) $row->channel_group,
                'source' => $query->group === 'source_medium' ? (string) $row->source_name : null,
                'medium' => $query->group === 'source_medium' ? (string) $row->medium : null,
                ...$figures,
                'share_of_sessions' => $figures['sessions'] !== null && $sessionsTotal !== null && $sessionsTotal > 0
                    ? $figures['sessions'] / $sessionsTotal
                    : null,
            ];
        }

        $trend = [];

        foreach (
            $this->base($workspaceId, $ids, $query->from, $query->until)
                ->select('date')->selectRaw($this->sums())->groupBy('date')->orderBy('date')->get() as $row
        ) {
            $trend[] = ['date' => (string) $row->date, ...$this->shape((array) $row)];
        }

        return new Ga4TrafficView(
            from: $query->from->format('Y-m-d'),
            until: $query->until->format('Y-m-d'),
            previousFrom: $previousFrom->format('Y-m-d'),
            previousUntil: $previousUntil->format('Y-m-d'),
            group: $query->group === 'source_medium' ? 'source_medium' : 'channel',
            totals: $totals,
            previous: $previous,
            change: [
                'sessions' => $this->change($totals['sessions'], $previous['sessions']),
                'engaged_sessions' => $this->change($totals['engaged_sessions'], $previous['engaged_sessions']),
                'page_views' => $this->change($totals['page_views'], $previous['page_views']),
            ],
            trend: $trend,
            rows: $rows,
            rowCount: $rowCount,
            page: $page,
            perPage: $perPage,
            quality: $this->quality($workspaceId, $ids, $query, $days),
            sources: array_map(static fn ($source): array => [
                'id' => $source->id,
                'name' => $source->name,
                'client_name' => $source->clientName,
                'freshness' => $source->freshness->value,
                'last_synced_at' => $source->lastSyncedAt?->format(DATE_ATOM),
            ], $sources),
        );
    }

    /** @param list<string> $ids */
    private function base(string $workspaceId, array $ids, DateTimeImmutable $from, DateTimeImmutable $until): Builder
    {
        return DB::table('ga4_traffic_daily')
            ->where('workspace_id', $workspaceId)
            ->whereIn('measurement_source_id', $ids === [] ? ['00000000-0000-0000-0000-000000000000'] : $ids)
            ->whereBetween('date', [$from->format('Y-m-d'), $until->format('Y-m-d')]);
    }

    private function sums(): string
    {
        return 'sum(sessions) as sessions, sum(engaged_sessions) as engaged_sessions, sum(page_views) as page_views, sum(engagement_seconds) as engagement_seconds';
    }

    /** @return array{sessions: ?int, engaged_sessions: ?int, page_views: ?int, engagement_seconds: ?int, engagement_rate: ?float, engagement_seconds_per_session: ?float} */
    private function figures(Builder $query): array
    {
        $row = $query->selectRaw($this->sums())->first();

        return $this->shape($row === null ? [] : (array) $row);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{sessions: ?int, engaged_sessions: ?int, page_views: ?int, engagement_seconds: ?int, engagement_rate: ?float, engagement_seconds_per_session: ?float}
     */
    private function shape(array $row): array
    {
        $int = static fn (string $key): ?int => isset($row[$key]) ? (int) $row[$key] : null;

        $sessions = $int('sessions');
        $engaged = $int('engaged_sessions');
        $seconds = $int('engagement_seconds');

        return [
            'sessions' => $sessions,
            'engaged_sessions' => $engaged,
            'page_views' => $int('page_views'),
            'engagement_seconds' => $seconds,
            'engagement_rate' => $sessions !== null && $engaged !== null && $sessions > 0 ? $engaged / $sessions : null,
            'engagement_seconds_per_session' => $sessions !== null && $seconds !== null && $sessions > 0 ? $seconds / $sessions : null,
        ];
    }

    /** Percentage change as a fraction; null when either side is missing or the base is zero ("up from nothing" has no percentage). */
    private function change(?int $now, ?int $before): ?float
    {
        return $now === null || $before === null || $before === 0 ? null : ($now - $before) / $before;
    }

    /**
     * What the stored rows say about their own completeness.
     *
     * @param  list<string>  $ids
     * @return array<string, mixed>
     */
    private function quality(string $workspaceId, array $ids, Ga4TrafficQuery $query, int $days): array
    {
        $labels = $this->base($workspaceId, $ids, $query->from, $query->until)->distinct()->pluck('source')->all();
        $zones = $this->base($workspaceId, $ids, $query->from, $query->until)->whereNotNull('time_zone')->distinct()->pluck('time_zone')->all();
        $range = $this->base($workspaceId, $ids, $query->from, $query->until)
            ->selectRaw('min(date) as first_day, max(date) as last_day, count(distinct date) as days_present')->first();

        $source = $labels === []
            ? null
            : MetricSource::weakest(array_map(static fn ($label): MetricSource => MetricSource::from((string) $label), $labels));
        $present = (int) ($range->days_present ?? 0);

        return [
            'source' => $source?->value,
            'source_label' => $source?->label(),
            'is_sandbox' => $source === MetricSource::Sandbox,
            'time_zones' => array_values(array_map('strval', $zones)),
            'days_in_period' => $days,
            'days_present' => $present,
            'first_day' => $range->first_day ?? null,
            'last_day' => $range->last_day ?? null,
            'is_complete' => $present === $days,
            'no_data' => $present === 0,
        ];
    }
}
