<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Tracking\Contract\TrackingAnalyticsReadModel;
use App\Domain\Tracking\Funnel\FunnelCohort;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reads the first-party record back. Plain SQL over indexed columns, every statement scoped by workspace and project, and
 * every count over `valid` traffic only — the excluded number is returned beside the counts, never silently dropped.
 */
final readonly class EloquentTrackingAnalyticsReadModel implements TrackingAnalyticsReadModel
{
    private const int SESSION_CAP = 100_000;

    public function cohort(string $workspaceId, string $projectId, ?string $linkId, DateTimeImmutable $from, DateTimeImmutable $to): FunnelCohort
    {
        [$where, $bindings] = $this->sessionScope($workspaceId, $projectId, $linkId, $from, $to);

        $rows = DB::select(
            "SELECT s.id, s.started_at,
                (SELECT min(e.occurred_at) FROM tracking_events e WHERE e.session_id = s.id AND e.name = 'landing_view' AND e.traffic_class = 'valid') AS landing,
                (SELECT min(e.occurred_at) FROM tracking_events e WHERE e.session_id = s.id AND e.name = 'form_start' AND e.traffic_class = 'valid') AS form_start,
                (SELECT min(f.submitted_at) FROM form_submissions f WHERE f.session_id = s.id AND f.traffic_class = 'valid') AS submit,
                (SELECT f.lead_id FROM form_submissions f WHERE f.session_id = s.id AND f.lead_id IS NOT NULL ORDER BY f.submitted_at LIMIT 1) AS lead
             FROM tracking_sessions s WHERE {$where} ORDER BY s.started_at LIMIT ".(self::SESSION_CAP + 1),
            $bindings,
        );

        $truncated = count($rows) > self::SESSION_CAP;
        $rows = array_slice($rows, 0, self::SESSION_CAP);
        $sessions = [];
        $leadIds = [];

        foreach ($rows as $r) {
            $sessions[] = [
                'started' => $this->ts($r->started_at) ?? 0,
                'landing' => $this->ts($r->landing),
                'form_start' => $this->ts($r->form_start),
                'submit' => $this->ts($r->submit),
                'lead' => is_string($r->lead) ? $r->lead : null,
            ];

            if (is_string($r->lead)) {
                $leadIds[$r->lead] = true;
            }
        }

        $submissions = (int) (DB::selectOne(
            "SELECT count(*) AS n FROM form_submissions f WHERE f.traffic_class = 'valid' AND f.session_id IN (SELECT s.id FROM tracking_sessions s WHERE {$where})",
            $bindings,
        )->n ?? 0);

        $excluded = (int) (DB::selectOne(
            'SELECT count(*) AS n FROM tracking_sessions s WHERE s.workspace_id = ? AND s.project_id = ? AND s.traffic_class <> ? AND s.started_at >= ? AND s.started_at < ?'.($linkId !== null ? ' AND s.tracking_link_id = ?' : ''),
            [$workspaceId, $projectId, 'valid', $this->at($from), $this->at($to), ...($linkId !== null ? [$linkId] : [])],
        )->n ?? 0);

        return new FunnelCohort($sessions, $submissions, $this->leads(array_keys($leadIds)), $truncated, $excluded);
    }

    public function breakdown(string $workspaceId, string $projectId, ?string $linkId, string $dimension, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        [$where, $bindings] = $this->sessionScope($workspaceId, $projectId, $linkId, $from, $to);
        /* Chosen from a closed list by the caller; never interpolated from a request. */
        $dim = match ($dimension) {
            'platform' => "COALESCE(tl.platform, '(none)')",
            'campaign' => "COALESCE(tl.campaign, '(none)')",
            'ad_group' => "COALESCE(tl.ad_group, '(none)')",
            'ad' => "COALESCE(tl.ad, '(none)')",
            'creative' => "COALESCE(tl.creative, '(none)')",
            'device' => 's.device_class',
            'browser' => 's.browser_class',
            'referrer' => "COALESCE(s.referrer_host, '(direct)')",
            'utm_source' => "COALESCE(tl.utm_source, '(none)')",
            'landing' => "COALESCE((SELECT e.landing_path FROM tracking_events e WHERE e.session_id = s.id AND e.name = 'link_click' LIMIT 1), '(none)')",
            'date' => "to_char(s.started_at AT TIME ZONE 'UTC', 'YYYY-MM-DD')",
            default => "lpad(extract(hour FROM s.started_at AT TIME ZONE 'UTC')::int::text, 2, '0')",
        };

        $rows = DB::select(
            "SELECT dim, count(*) AS sessions,
                count(*) FILTER (WHERE has_landing) AS landing_sessions,
                count(*) FILTER (WHERE has_start) AS start_sessions,
                coalesce(sum(subs), 0) AS submissions,
                count(DISTINCT lead_id) AS leads,
                count(DISTINCT lead_id) FILTER (WHERE qualified) AS qualified,
                count(DISTINCT lead_id) FILTER (WHERE won) AS won
             FROM (
                SELECT {$dim} AS dim,
                    EXISTS (SELECT 1 FROM tracking_events e WHERE e.session_id = s.id AND e.name = 'landing_view' AND e.traffic_class = 'valid') AS has_landing,
                    EXISTS (SELECT 1 FROM tracking_events e WHERE e.session_id = s.id AND e.name = 'form_start' AND e.traffic_class = 'valid') AS has_start,
                    (SELECT count(*) FROM form_submissions f WHERE f.session_id = s.id AND f.traffic_class = 'valid') AS subs,
                    fl.lead_id, (l.qualified_at IS NOT NULL) AS qualified, (l.stage = 'won') AS won
                FROM tracking_sessions s
                LEFT JOIN tracking_links tl ON tl.id = s.tracking_link_id
                LEFT JOIN LATERAL (SELECT f.lead_id FROM form_submissions f WHERE f.session_id = s.id AND f.lead_id IS NOT NULL ORDER BY f.submitted_at LIMIT 1) fl ON true
                LEFT JOIN leads l ON l.id = fl.lead_id
                WHERE {$where}
             ) t GROUP BY dim ORDER BY sessions DESC, dim LIMIT 50",
            $bindings,
        );

        return array_values(array_map(function (stdClass $r): array {
            $sessions = (int) $r->sessions;
            $landing = (int) $r->landing_sessions;
            $starts = (int) $r->start_sessions;

            return [
                'key' => (string) $r->dim,
                'sessions' => $sessions,
                /* Reported by the destination page: no report at all is "not reported", never zero. */
                'landing_views' => $landing === 0 && $sessions > 0 ? null : $landing,
                'form_starts' => $starts === 0 && $sessions > 0 ? null : $starts,
                'submissions' => (int) $r->submissions,
                'leads' => (int) $r->leads,
                'qualified' => (int) $r->qualified,
                'sales' => (int) $r->won,
            ];
        }, $rows));
    }

    public function leadTimeline(string $workspaceId, string $leadId): array
    {
        $timeline = [];

        $subs = DB::table('form_submissions')->where('workspace_id', $workspaceId)->where('lead_id', $leadId)->get();
        $sessionIds = $subs->pluck('session_id')->filter()->unique()->values()->all();

        if ($sessionIds !== []) {
            $events = DB::table('tracking_events')->where('workspace_id', $workspaceId)->whereIn('session_id', $sessionIds)
                ->whereIn('name', ['link_click', 'landing_view', 'form_view', 'form_start', 'form_submit', 'whatsapp_click'])
                ->orderBy('occurred_at')->limit(200)->get();

            foreach ($events as $e) {
                $clicks = $this->assoc($e->click_ids);
                $timeline[] = [
                    'at' => $this->iso($e->occurred_at),
                    'name' => (string) $e->name,
                    'source' => (string) $e->source === 'first_party' ? 'first_party' : 'server',
                    'evidence' => array_filter([
                        'utm_campaign' => $this->assoc($e->utm)['utm_campaign'] ?? null,
                        'utm_source' => $this->assoc($e->utm)['utm_source'] ?? null,
                        'click_id_types' => $clicks === [] ? null : array_keys($clicks),
                        'referrer_host' => $e->referrer_host,
                        'landing_path' => $e->landing_path,
                        'traffic_class' => $e->traffic_class,
                    ], static fn (mixed $v): bool => $v !== null),
                ];
            }
        }

        foreach (DB::table('lead_events')->where('workspace_id', $workspaceId)->where('lead_id', $leadId)->orderBy('occurred_at')->limit(200)->get() as $e) {
            $name = match ((string) $e->type) {
                'received' => 'lead_created',
                'contacted' => 'lead_contacted',
                'assigned' => 'lead_assigned',
                'appointment_booked' => 'appointment_booked',
                'opportunity_created' => 'opportunity_created',
                'stage_changed' => match ((string) $e->to_stage) {
                    'qualified' => 'lead_qualified',
                    'won' => 'sale_completed',
                    'lost', 'disqualified' => 'lead_lost',
                    'contacted' => 'lead_contact_attempt',
                    default => null,
                },
                default => null,
            };

            if ($name !== null) {
                $timeline[] = [
                    'at' => $this->iso($e->occurred_at),
                    'name' => $name,
                    'source' => 'lead_pipeline',
                    /* Who did it is a person's name elsewhere; here, only whether a person did. */
                    'evidence' => ['by' => $e->actor_id === null ? 'system' : 'person'],
                ];
            }
        }

        usort($timeline, static fn (array $a, array $b): int => strcmp((string) $a['at'], (string) $b['at']));

        return $timeline;
    }

    public function followUp(string $workspaceId, string $projectId, DateTimeImmutable $from, DateTimeImmutable $to, int $slaMinutes): array
    {
        $row = DB::selectOne(
            "SELECT count(*) AS leads,
                count(*) FILTER (WHERE first_contacted_at IS NOT NULL) AS contacted,
                count(*) FILTER (WHERE first_contacted_at IS NULL AND stage = 'new') AS uncontacted,
                count(*) FILTER (WHERE first_contacted_at IS NULL AND stage = 'new' AND received_at < ?) AS overdue,
                count(*) FILTER (WHERE first_contacted_at IS NOT NULL AND first_contacted_at <= received_at + (? * interval '1 minute')) AS within_sla,
                count(*) FILTER (WHERE qualified_at IS NOT NULL) AS qualified,
                count(*) FILTER (WHERE attempt_at IS NOT NULL) AS attempted,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (first_contacted_at - received_at))) FILTER (WHERE first_contacted_at IS NOT NULL AND first_contacted_at >= received_at) AS median_seconds,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (attempt_at - received_at))) FILTER (WHERE attempt_at IS NOT NULL AND attempt_at >= received_at) AS median_attempt_seconds
             FROM (
                SELECT l.stage, l.received_at, l.first_contacted_at, l.qualified_at,
                    (SELECT min(e.occurred_at) FROM lead_events e WHERE e.lead_id = l.id AND e.type = 'stage_changed') AS attempt_at
                FROM leads l
                WHERE l.workspace_id = ? AND l.project_id = ? AND l.source_kind = 'form_submission' AND l.merged_into_id IS NULL
                    AND l.received_at >= ? AND l.received_at < ?
             ) t",
            [now()->subMinutes($slaMinutes)->format('Y-m-d H:i:sP'), $slaMinutes, $workspaceId, $projectId, $this->at($from), $this->at($to)],
        );

        $leads = (int) ($row->leads ?? 0);
        $contacted = (int) ($row->contacted ?? 0);

        return [
            'sla_minutes' => $slaMinutes,
            'leads' => $leads,
            'contacted' => $contacted,
            'qualified' => (int) ($row->qualified ?? 0),
            'uncontacted' => (int) ($row->uncontacted ?? 0),
            'overdue_uncontacted' => (int) ($row->overdue ?? 0),
            'contact_rate' => $leads === 0 ? null : round($contacted / $leads, 4),
            'sla_compliance' => $leads === 0 ? null : round((int) ($row->within_sla ?? 0) / $leads, 4),
            'median_seconds_to_first_contact' => $row->median_seconds === null ? null : (int) round((float) $row->median_seconds),
            'attempted' => (int) ($row->attempted ?? 0),
            /* An attempt is the lead's first move out of "new"; without one there is nothing to measure, so null. */
            'median_seconds_to_first_attempt' => $row->median_attempt_seconds === null ? null : (int) round((float) $row->median_attempt_seconds),
        ];
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function sessionScope(string $workspaceId, string $projectId, ?string $linkId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $where = "s.workspace_id = ? AND s.project_id = ? AND s.traffic_class = 'valid' AND s.started_at >= ? AND s.started_at < ?";
        $bindings = [$workspaceId, $projectId, $this->at($from), $this->at($to)];

        if ($linkId !== null) {
            $where .= ' AND s.tracking_link_id = ?';
            $bindings[] = $linkId;
        }

        return [$where, $bindings];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{received: int, contacted: ?int, qualified: ?int, appointment: ?int, opportunity: ?int, won: ?int}>
     */
    private function leads(array $ids): array
    {
        $out = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $rows = DB::select(
                "SELECT l.id, l.received_at, l.first_contacted_at, l.qualified_at, l.stage, l.closed_at,
                    (SELECT min(occurred_at) FROM lead_events WHERE lead_id = l.id AND type = 'appointment_booked') AS appointment,
                    (SELECT min(occurred_at) FROM lead_events WHERE lead_id = l.id AND type = 'opportunity_created') AS opportunity
                 FROM leads l WHERE l.id IN ({$marks})",
                $chunk,
            );

            foreach ($rows as $r) {
                $out[(string) $r->id] = [
                    'received' => $this->ts($r->received_at) ?? 0,
                    'contacted' => $this->ts($r->first_contacted_at),
                    'qualified' => $this->ts($r->qualified_at),
                    'appointment' => $this->ts($r->appointment),
                    'opportunity' => $this->ts($r->opportunity),
                    'won' => (string) $r->stage === 'won' ? $this->ts($r->closed_at) : null,
                ];
            }
        }

        return $out;
    }

    private function at(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:sP');
    }

    private function ts(mixed $value): ?int
    {
        return is_string($value) && $value !== '' ? (new DateTimeImmutable($value))->getTimestamp() : null;
    }

    private function iso(mixed $value): string
    {
        return (new DateTimeImmutable((string) $value))->format(DATE_ATOM);
    }

    /** @return array<string, string> */
    private function assoc(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : [];
        $out = [];

        foreach (is_array($decoded) ? $decoded : [] as $k => $v) {
            if (is_scalar($v)) {
                $out[(string) $k] = (string) $v;
            }
        }

        return $out;
    }
}
