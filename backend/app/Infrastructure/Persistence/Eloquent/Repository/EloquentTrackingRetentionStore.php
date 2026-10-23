<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Tracking\Contract\TrackingRetentionStore;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class EloquentTrackingRetentionStore implements TrackingRetentionStore
{
    public function purge(DateTimeImmutable $now): array
    {
        $totals = ['rolled_up' => 0, 'events_deleted' => 0, 'sessions_deleted' => 0, 'submissions_anonymised' => 0, 'dispatches_anonymised' => 0];

        $projects = DB::table('tracking_events')->select('project_id')->distinct()->pluck('project_id')
            ->merge(DB::table('tracking_sessions')->select('project_id')->distinct()->pluck('project_id'))
            ->merge(DB::table('form_submissions')->select('project_id')->distinct()->pluck('project_id'))
            ->unique();

        foreach ($projects as $projectId) {
            $days = (int) (DB::table('tracking_settings')->where('project_id', $projectId)->value('retention_days') ?? 400);
            $cutoff = $now->modify(sprintf('-%d days', $days))->format('Y-m-d H:i:sP');

            DB::transaction(function () use ($projectId, $cutoff, &$totals): void {
                $totals['rolled_up'] += DB::affectingStatement(
                    "INSERT INTO tracking_daily_rollups (project_id, tracking_link_id, day, event, traffic_class, events, visitors)
                     SELECT project_id, tracking_link_id, (occurred_at AT TIME ZONE 'UTC')::date, name, traffic_class, count(*), count(DISTINCT visitor_id)
                     FROM tracking_events WHERE project_id = ? AND occurred_at < ?
                     GROUP BY project_id, tracking_link_id, (occurred_at AT TIME ZONE 'UTC')::date, name, traffic_class
                     ON CONFLICT (project_id, day, event, traffic_class, tracking_link_id)
                     DO UPDATE SET events = tracking_daily_rollups.events + EXCLUDED.events, visitors = tracking_daily_rollups.visitors + EXCLUDED.visitors",
                    [$projectId, $cutoff],
                );
                $totals['events_deleted'] += DB::table('tracking_events')->where('project_id', $projectId)->where('occurred_at', '<', $cutoff)->delete();
                $totals['sessions_deleted'] += DB::table('tracking_sessions')->where('project_id', $projectId)->where('started_at', '<', $cutoff)->delete();
                $totals['submissions_anonymised'] += DB::table('form_submissions')->where('project_id', $projectId)->where('submitted_at', '<', $cutoff)
                    ->where(static fn ($q) => $q->whereNotNull('visitor_id')->orWhereNotNull('session_id')->orWhereNotNull('touchpoint_id'))
                    ->update(['visitor_id' => null, 'session_id' => null, 'touchpoint_id' => null]);
                $totals['dispatches_anonymised'] += DB::table('provider_event_dispatches')->where('project_id', $projectId)->where('created_at', '<', $cutoff)
                    ->where('click_ids', '<>', DB::raw("'{}'::jsonb"))->update(['click_ids' => '{}']);
            });
        }

        return $totals;
    }
}
