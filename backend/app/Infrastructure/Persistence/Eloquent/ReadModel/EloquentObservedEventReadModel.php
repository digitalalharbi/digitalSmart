<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Measurement\Contract\ObservedEventReadModel;
use App\Application\Shared\Contract\Clock;
use Illuminate\Support\Facades\DB;

/**
 * What the sources have actually been seen to report.
 *
 * Two queries, both over an aggregate table, both scoped to the sources the caller already
 * established it may see. The scoping is passed in rather than re-derived here: this read
 * model has no business knowing about project assignments, and duplicating that rule is how
 * two versions of it come to disagree.
 */
final readonly class EloquentObservedEventReadModel implements ObservedEventReadModel
{
    public function __construct(private Clock $clock) {}

    /**
     * @param  list<string>  $sourceIds
     * @return array<string, array{count: int, has_value: bool, last_date: string}>
     */
    public function countsByRawName(string $workspaceId, array $sourceIds, int $days): array
    {
        if ($sourceIds === []) {
            return [];
        }

        $rows = DB::table('observed_event_counts')
            ->where('workspace_id', $workspaceId)
            ->whereIn('measurement_source_id', $sourceIds)
            ->where('date', '>=', $this->since($days))
            ->selectRaw(
                'raw_event_name, sum(event_count) as total, '
                // A value *anywhere* in the window is enough to say the event carries one.
                .'count(value_minor) as valued, max(date) as last_date'
            )
            ->groupBy('raw_event_name')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $name = (string) ($values['raw_event_name'] ?? '');

            if ($name === '') {
                continue;
            }

            $counts[$name] = [
                'count' => (int) ($values['total'] ?? 0),
                'has_value' => ((int) ($values['valued'] ?? 0)) > 0,
                'last_date' => (string) ($values['last_date'] ?? ''),
            ];
        }

        return $counts;
    }

    /**
     * Raw names seen but mapped to nothing.
     *
     * The honest centrepiece of the health check. An event arriving and counting towards
     * nothing is invisible everywhere else in the product — it is not a zero on a chart, it is
     * simply absent — so this is the only place it can be noticed.
     *
     * The mapping join deliberately matches on *name* alone when a mapping has no source: a
     * name mapped "from any source" covers every source, which is what nullable means there.
     *
     * @param  list<string>  $sourceIds
     * @return list<string>
     */
    public function unmappedRawNames(string $workspaceId, array $sourceIds, int $days): array
    {
        if ($sourceIds === []) {
            return [];
        }

        $rows = DB::table('observed_event_counts as counts')
            ->where('counts.workspace_id', $workspaceId)
            ->whereIn('counts.measurement_source_id', $sourceIds)
            ->where('counts.date', '>=', $this->since($days))
            ->whereNotExists(function (\Illuminate\Database\Query\Builder $sub) use ($workspaceId): void {
                $sub->select(DB::raw(1))
                    ->from('event_mappings as mappings')
                    ->where('mappings.workspace_id', $workspaceId)
                    ->whereColumn('mappings.raw_event_name', 'counts.raw_event_name')
                    ->where(function (\Illuminate\Database\Query\Builder $scope): void {
                        $scope
                            ->whereNull('mappings.measurement_source_id')
                            ->orWhereColumn('mappings.measurement_source_id', 'counts.measurement_source_id');
                    });
            })
            ->distinct()
            ->orderBy('counts.raw_event_name')
            ->pluck('counts.raw_event_name');

        $names = [];

        foreach ($rows as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function since(int $days): string
    {
        return $this->clock->now()->modify('-'.max(1, $days - 1).' days')->format('Y-m-d');
    }
}
