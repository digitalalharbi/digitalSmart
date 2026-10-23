<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Tracking\Contract\TrackingOperationsReadModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class EloquentTrackingOperationsReadModel implements TrackingOperationsReadModel
{
    public function response(string $workspaceId, string $projectId, DateTimeImmutable $from, DateTimeImmutable $to, ?int $slaMinutes): array
    {
        $rows = $this->aggregate($workspaceId, $projectId, $from, $to, $slaMinutes, 'total');

        return $rows[0] ?? $this->empty(null, null, $slaMinutes);
    }

    public function responseByOwner(string $workspaceId, string $projectId, DateTimeImmutable $from, DateTimeImmutable $to, ?int $slaMinutes): array
    {
        return $this->aggregate($workspaceId, $projectId, $from, $to, $slaMinutes, 'owner');
    }

    public function responseByLink(string $workspaceId, string $projectId, DateTimeImmutable $from, DateTimeImmutable $to, ?int $slaMinutes): array
    {
        $out = [];

        foreach ($this->aggregate($workspaceId, $projectId, $from, $to, $slaMinutes, 'link') as $row) {
            if ($row['owner_id'] !== null) {
                $out[$row['owner_id']] = $row;
            }
        }

        return $out;
    }

    public function touches(string $workspaceId, string $projectId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $row = DB::selectOne(
            "SELECT count(*) AS leads,
                count(*) FILTER (WHERE t.submission_link IS NOT NULL) AS tracked,
                count(*) FILTER (WHERE t.submission_link IS NOT NULL AND t.first_link IS NOT NULL AND t.first_link = t.submission_link) AS same_touch,
                count(*) FILTER (WHERE t.submission_link IS NOT NULL AND t.first_link IS NOT NULL AND t.first_link <> t.submission_link) AS different_touch,
                count(*) FILTER (WHERE t.submission_link IS NOT NULL AND t.first_link IS NULL) AS first_unknown
             FROM (
                SELECT l.id,
                    (SELECT s.tracking_link_id FROM form_submissions f JOIN tracking_sessions s ON s.id = f.session_id
                        WHERE f.lead_id = l.id ORDER BY f.submitted_at LIMIT 1) AS submission_link,
                    (SELECT s2.tracking_link_id FROM form_submissions f2 JOIN tracking_sessions s ON s.id = f2.session_id
                        JOIN tracking_sessions s2 ON s2.visitor_id = s.visitor_id AND s2.project_id = s.project_id AND s2.tracking_link_id IS NOT NULL
                        WHERE f2.lead_id = l.id ORDER BY s2.started_at LIMIT 1) AS first_link
                FROM leads l
                WHERE l.workspace_id = ? AND l.project_id = ? AND l.source_kind = 'form_submission' AND l.merged_into_id IS NULL
                    AND l.received_at >= ? AND l.received_at < ?
             ) t",
            [$workspaceId, $projectId, $from->format('Y-m-d H:i:sP'), $to->format('Y-m-d H:i:sP')],
        );

        return [
            'leads' => (int) ($row->leads ?? 0), 'tracked' => (int) ($row->tracked ?? 0), 'same_touch' => (int) ($row->same_touch ?? 0),
            'different_touch' => (int) ($row->different_touch ?? 0), 'first_touch_unknown' => (int) ($row->first_unknown ?? 0),
        ];
    }

    public function platformReported(string $workspaceId, array $entities, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($entities === []) {
            return ['clicks' => null, 'results' => null, 'entities' => 0];
        }

        $clicks = 0;
        $results = 0;
        $anyClicks = false;
        $anyResults = false;

        foreach ($entities as $entity) {
            $row = DB::table('daily_metrics')
                ->where('workspace_id', $workspaceId)->where('entity_type', $entity['level'])->where('entity_id', $entity['id'])
                ->where('date', '>=', $from->format('Y-m-d'))->where('date', '<', $to->format('Y-m-d'))
                ->selectRaw('sum(coalesce(link_clicks, clicks)) as clicks, sum(conversions) as results, count(clicks) as c, count(conversions) as r')->first();

            if ($row instanceof stdClass) {
                $clicks += (int) $row->clicks;
                $results += (int) $row->results;
                $anyClicks = $anyClicks || (int) $row->c > 0;
                $anyResults = $anyResults || (int) $row->r > 0;
            }
        }

        return ['clicks' => $anyClicks ? $clicks : null, 'results' => $anyResults ? $results : null, 'entities' => count($entities)];
    }

    /** @return list<array{owner_id: ?string, owner_name: ?string, leads: int, contacted: int, qualified: int, attempted: int, met_sla: ?int, overdue: ?int, median_attempt_seconds: ?int, median_contact_seconds: ?int}> */
    private function aggregate(string $workspaceId, string $projectId, DateTimeImmutable $from, DateTimeImmutable $to, ?int $sla, string $by): array
    {
        $interval = ($sla ?? 0).' minutes';
        /* For the per-link grouping the link id rides in the `owner_id` slot: the row shape is the same and only the key differs. */
        $group = match ($by) {
            'owner' => 't.owner_id, t.owner_name',
            'link' => 't.link_id',
            default => '',
        };
        $select = match ($by) {
            'owner' => 't.owner_id, t.owner_name,',
            'link' => 't.link_id AS owner_id, NULL::text AS owner_name,',
            default => 'NULL::uuid AS owner_id, NULL::text AS owner_name,',
        };

        $rows = DB::select(
            "SELECT {$select} count(*) AS leads,
                count(*) FILTER (WHERE t.first_contacted_at IS NOT NULL) AS contacted,
                count(*) FILTER (WHERE t.qualified_at IS NOT NULL) AS qualified,
                count(*) FILTER (WHERE t.attempt_at IS NOT NULL) AS attempted,
                count(*) FILTER (WHERE ?::int IS NOT NULL AND t.attempt_at IS NOT NULL AND t.attempt_at <= t.received_at + ?::interval) AS met,
                count(*) FILTER (WHERE ?::int IS NOT NULL AND t.attempt_at IS NULL AND t.first_contacted_at IS NULL AND t.stage = 'new' AND t.received_at + ?::interval < now()) AS overdue,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (t.attempt_at - t.received_at))) FILTER (WHERE t.attempt_at >= t.received_at) AS med_attempt,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (t.first_contacted_at - t.received_at))) FILTER (WHERE t.first_contacted_at >= t.received_at) AS med_contact
             FROM (
                SELECT l.owner_id, u.name AS owner_name,
                    (SELECT s.tracking_link_id FROM form_submissions f JOIN tracking_sessions s ON s.id = f.session_id WHERE f.lead_id = l.id ORDER BY f.submitted_at LIMIT 1) AS link_id,
                    l.stage, l.received_at, l.first_contacted_at, l.qualified_at,
                    (SELECT min(e.occurred_at) FROM lead_events e WHERE e.lead_id = l.id AND e.type = 'stage_changed') AS attempt_at
                FROM leads l LEFT JOIN users u ON u.id = l.owner_id
                WHERE l.workspace_id = ? AND l.project_id = ? AND l.source_kind = 'form_submission' AND l.merged_into_id IS NULL
                    AND l.received_at >= ? AND l.received_at < ?
             ) t".($by === 'owner' ? " GROUP BY {$group} ORDER BY leads DESC, t.owner_name" : ($by === 'link' ? " GROUP BY {$group}" : '')),
            [$sla, $interval, $sla, $interval, $workspaceId, $projectId, $from->format('Y-m-d H:i:sP'), $to->format('Y-m-d H:i:sP')],
        );

        $out = [];

        foreach ($rows as $r) {
            $out[] = [
                'owner_id' => is_string($r->owner_id) ? $r->owner_id : null,
                'owner_name' => is_string($r->owner_name) ? $r->owner_name : null,
                'leads' => (int) $r->leads, 'contacted' => (int) $r->contacted, 'qualified' => (int) $r->qualified, 'attempted' => (int) $r->attempted,
                'met_sla' => $sla === null ? null : (int) $r->met,
                'overdue' => $sla === null ? null : (int) $r->overdue,
                'median_attempt_seconds' => $r->med_attempt === null ? null : (int) round((float) $r->med_attempt),
                'median_contact_seconds' => $r->med_contact === null ? null : (int) round((float) $r->med_contact),
            ];
        }

        return $out;
    }

    /** @return array{owner_id: ?string, owner_name: ?string, leads: int, contacted: int, qualified: int, attempted: int, met_sla: ?int, overdue: ?int, median_attempt_seconds: ?int, median_contact_seconds: ?int} */
    private function empty(?string $id, ?string $name, ?int $sla): array
    {
        return ['owner_id' => $id, 'owner_name' => $name, 'leads' => 0, 'contacted' => 0, 'qualified' => 0, 'attempted' => 0, 'met_sla' => $sla === null ? null : 0, 'overdue' => $sla === null ? null : 0, 'median_attempt_seconds' => null, 'median_contact_seconds' => null];
    }
}
