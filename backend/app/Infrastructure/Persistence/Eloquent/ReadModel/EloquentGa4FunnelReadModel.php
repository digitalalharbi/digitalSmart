<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Measurement\Contract\Ga4FunnelReadModel;
use App\Domain\Advertising\MetricSource;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/** Sums `observed_event_counts` for Google's four documented e-commerce events, over the sources it is handed. */
final readonly class EloquentGa4FunnelReadModel implements Ga4FunnelReadModel
{
    /** In the order a shopper meets them. The names are Google's own; this product maps no other event to a stage. */
    public const array STAGES = ['view_item', 'add_to_cart', 'begin_checkout', 'purchase'];

    public function read(string $workspaceId, array $sources, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $ids = array_map(static fn ($source): string => $source->id, $sources);

        $base = static fn () => DB::table('observed_event_counts')
            ->where('workspace_id', $workspaceId)
            ->whereIn('measurement_source_id', $ids === [] ? ['00000000-0000-0000-0000-000000000000'] : $ids)
            ->whereBetween('date', [$from->format('Y-m-d'), $until->format('Y-m-d')]);

        $sums = $base()->whereIn('raw_event_name', self::STAGES)
            ->selectRaw('raw_event_name, sum(event_count) as total')->groupBy('raw_event_name')->pluck('total', 'raw_event_name');

        $stages = array_map(static fn (string $event): array => [
            'event' => $event,
            'count' => isset($sums[$event]) ? (int) $sums[$event] : null,
        ], self::STAGES);

        $labels = $base()->distinct()->pluck('source')->all();
        $present = (int) ($base()->selectRaw('count(distinct date) as days')->value('days') ?? 0);
        $zones = DB::table('ga4_traffic_daily')->where('workspace_id', $workspaceId)
            ->whereIn('measurement_source_id', $ids === [] ? ['00000000-0000-0000-0000-000000000000'] : $ids)
            ->whereNotNull('time_zone')->distinct()->pluck('time_zone')->all();

        return [
            'stages' => $stages,
            'days_present' => $present,
            'days_in_period' => (int) $from->diff($until)->days + 1,
            'source' => $labels === [] ? null : MetricSource::weakest(array_map(static fn ($label): MetricSource => MetricSource::from((string) $label), $labels))->value,
            'time_zones' => array_values(array_map('strval', $zones)),
        ];
    }
}
