<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Measurement\Contract\TrafficWriter;
use App\Domain\Advertising\MetricSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Writes traffic rows on the natural key, so a re-read replaces what it already wrote. */
final readonly class EloquentTrafficWriter implements TrafficWriter
{
    private const int CHUNK = 500;

    public function upsert(
        string $workspaceId,
        string $sourceId,
        array $records,
        MetricSource $source,
        ?string $timeZone,
    ): int {
        if ($records === []) {
            return 0;
        }

        $now = now();
        $rows = [];

        foreach ($records as $record) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'workspace_id' => $workspaceId,
                'measurement_source_id' => $sourceId,
                'date' => $record->date,
                'channel_group' => $record->channelGroup,
                'source_name' => $record->source,
                'medium' => $record->medium,
                'sessions' => $record->sessions,
                'users' => $record->totalUsers,
                'new_users' => $record->newUsers,
                'engaged_sessions' => $record->engagedSessions,
                'engagement_seconds' => $record->engagementSeconds,
                'page_views' => $record->pageViews,
                'time_zone' => $timeZone,
                'source' => $source->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $written = 0;

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('ga4_traffic_daily')->upsert(
                $chunk,
                ['measurement_source_id', 'date', 'channel_group', 'source_name', 'medium'],
                ['sessions', 'users', 'new_users', 'engaged_sessions', 'engagement_seconds', 'page_views', 'time_zone', 'source', 'updated_at'],
            );

            $written += count($chunk);
        }

        return $written;
    }
}
