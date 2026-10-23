<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Tracking\Contract\TrackingHealthReadModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class EloquentTrackingHealthReadModel implements TrackingHealthReadModel
{
    public function facts(string $workspaceId, string $projectId, ?string $linkId, DateTimeImmutable $now): array
    {
        $week = $now->modify('-7 days')->format('Y-m-d H:i:sP');
        $day = $now->modify('-24 hours')->format('Y-m-d H:i:sP');
        $linkEvents = $linkId === null ? '' : ' AND e.tracking_link_id = ?';
        $bind = static fn (array $base): array => $linkId === null ? $base : [...$base, $linkId];

        $e = DB::selectOne(
            "SELECT
                count(*) FILTER (WHERE e.occurred_at >= ?) AS events_24h,
                max(e.occurred_at) AS last_event_at,
                count(*) FILTER (WHERE e.name = 'link_click' AND e.occurred_at >= ?) AS clicks_7d,
                count(*) FILTER (WHERE e.name = 'landing_view' AND e.occurred_at >= ?) AS landing_views_7d,
                count(*) FILTER (WHERE e.name = 'link_click' AND e.occurred_at >= ? AND e.click_ids <> '{}'::jsonb) AS with_click_id
             FROM tracking_events e
             WHERE e.workspace_id = ? AND e.project_id = ? AND e.traffic_class = 'valid'{$linkEvents}",
            $bind([$day, $week, $week, $week, $workspaceId, $projectId]),
        );

        $links = (int) (DB::selectOne(
            "SELECT count(*) AS n FROM tracking_links WHERE workspace_id = ? AND project_id = ? AND status = 'active'".($linkId === null ? '' : ' AND id = ?'),
            $linkId === null ? [$workspaceId, $projectId] : [$workspaceId, $projectId, $linkId],
        )->n ?? 0);

        $leadsQuery = $linkId === null
            ? 'SELECT count(*) AS n, count(*) FILTER (WHERE attribution_evidence <> \'none\') AS evidence FROM leads WHERE workspace_id = ? AND project_id = ? AND merged_into_id IS NULL AND received_at >= ?'
            : 'SELECT count(*) AS n, count(*) FILTER (WHERE l.attribution_evidence <> \'none\') AS evidence FROM leads l WHERE l.workspace_id = ? AND l.project_id = ? AND l.merged_into_id IS NULL AND l.received_at >= ? AND l.id IN (SELECT lead_id FROM form_submissions WHERE tracking_link_id = ? AND lead_id IS NOT NULL)';
        $leads = DB::selectOne($leadsQuery, $linkId === null ? [$workspaceId, $projectId, $week] : [$workspaceId, $projectId, $week, $linkId]);

        $consent = ['unknown' => 0, 'granted' => 0, 'denied' => 0, 'not_required' => 0];

        foreach (DB::select(
            "SELECT consent_state, count(*) AS n FROM tracking_sessions WHERE workspace_id = ? AND project_id = ? AND traffic_class = 'valid' AND started_at >= ?".($linkId === null ? '' : ' AND tracking_link_id = ?').' GROUP BY consent_state',
            $linkId === null ? [$workspaceId, $projectId, $week] : [$workspaceId, $projectId, $week, $linkId],
        ) as $row) {
            $consent[(string) $row->consent_state] = (int) $row->n;
        }

        $clientId = DB::table('projects')->where('id', $projectId)->value('client_id');
        $ga = DB::table('measurement_sources')
            ->where('workspace_id', $workspaceId)->where('client_id', $clientId)->where('kind', 'analytics')->where('is_active', true)
            ->orderByDesc('last_synced_at')->first();
        $containers = (int) DB::table('measurement_sources')
            ->where('workspace_id', $workspaceId)->where('client_id', $clientId)->where('kind', 'tag_manager')->where('is_active', true)->count();

        return [
            'links_active' => $links,
            'events_24h' => (int) ($e->events_24h ?? 0),
            'last_event_at' => is_string($e->last_event_at ?? null) ? (new DateTimeImmutable($e->last_event_at))->getTimestamp() : null,
            'clicks_7d' => (int) ($e->clicks_7d ?? 0),
            'landing_views_7d' => (int) ($e->landing_views_7d ?? 0),
            'clicks_with_click_id_7d' => (int) ($e->with_click_id ?? 0),
            'leads_7d' => (int) ($leads->n ?? 0),
            'leads_with_evidence_7d' => (int) ($leads->evidence ?? 0),
            'consent' => $consent,
            'ga4' => $ga instanceof stdClass ? [
                'last_synced_at' => is_string($ga->last_synced_at) ? (new DateTimeImmutable($ga->last_synced_at))->getTimestamp() : null,
                'last_event_at' => is_string($ga->last_event_at) ? (new DateTimeImmutable($ga->last_event_at))->getTimestamp() : null,
            ] : null,
            'gtm_containers' => $containers,
        ];
    }

    public function recentEvents(string $workspaceId, string $projectId, ?string $touchpointId, ?DateTimeImmutable $after, int $limit): array
    {
        $query = DB::table('tracking_events')->where('workspace_id', $workspaceId)->where('project_id', $projectId);

        if ($touchpointId !== null) {
            $query->where('touchpoint_id', $touchpointId);
        } else {
            $query->where('occurred_at', '>=', now()->subMinutes(30));
        }

        if ($after !== null) {
            $query->where('created_at', '>', $after->format('Y-m-d H:i:s.uP'));
        }

        $rows = [];

        foreach ($query->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->reverse() as $r) {
            $rows[] = [
                'id' => (string) $r->id,
                'at' => (new DateTimeImmutable((string) $r->occurred_at))->format(DATE_ATOM),
                'recorded_at' => (new DateTimeImmutable((string) $r->created_at))->format('Y-m-d\TH:i:s.uP'),
                'event' => (string) $r->name,
                'source' => (string) $r->source,
                'status' => (string) $r->traffic_class,
                'event_id' => (string) $r->event_id,
                'consent' => (string) $r->consent_state,
                /* Nothing is dispatched to a provider yet; saying so beats an empty cell that could mean "unknown". */
                'destinations' => ['state' => 'NOT_CONFIGURED', 'detail' => 'Not dispatched to any provider.'],
            ];
        }

        return $rows;
    }
}
