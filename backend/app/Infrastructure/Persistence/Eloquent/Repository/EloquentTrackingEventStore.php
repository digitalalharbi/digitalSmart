<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Tracking\Contract\TrackingEventStore;
use App\Application\Tracking\DTO\LinkCounts;
use App\Application\Tracking\DTO\LinkTarget;
use App\Application\Tracking\DTO\Touchpoint;
use App\Domain\Tracking\ConsentState;
use App\Domain\Tracking\TrackingEventName;
use App\Domain\Tracking\TrafficClass;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final readonly class EloquentTrackingEventStore implements TrackingEventStore
{
    /** @param array<string, string> $clickIds */
    public function recordClick(
        LinkTarget $link, string $visitorId, string $sessionId, string $touchpointId, string $clickEventId, string $startEventId,
        TrafficClass $class, ConsentState $consent, ?string $referrerHost, string $deviceClass, string $browserClass,
        string $landingPath, array $clickIds, DateTimeImmutable $at,
    ): void {
        $when = $at->format('Y-m-d H:i:sP');

        DB::transaction(function () use ($link, $visitorId, $sessionId, $touchpointId, $clickEventId, $startEventId, $class, $consent, $referrerHost, $deviceClass, $browserClass, $landingPath, $clickIds, $when): void {
            DB::table('tracking_sessions')->insert([
                'id' => $sessionId, 'touchpoint_id' => $touchpointId, 'workspace_id' => $link->workspaceId, 'project_id' => $link->projectId,
                'tracking_link_id' => $link->id, 'visitor_id' => $visitorId, 'platform' => $link->platform,
                'channel' => $link->channel, 'referrer_host' => $referrerHost, 'device_class' => $deviceClass,
                'browser_class' => $browserClass, 'consent_state' => $consent->value, 'traffic_class' => $class->value,
                'started_at' => $when, 'last_seen_at' => $when,
            ]);

            foreach ([[TrackingEventName::LinkClick, $clickEventId], [TrackingEventName::SessionStart, $startEventId]] as [$name, $eventId]) {
                DB::table('tracking_events')->insert([
                    'id' => (string) Str::uuid7(), 'workspace_id' => $link->workspaceId, 'project_id' => $link->projectId,
                    'tracking_link_id' => $link->id, 'event_id' => $eventId, 'name' => $name->value,
                    'visitor_id' => $visitorId, 'session_id' => $sessionId, 'touchpoint_id' => $touchpointId,
                    'source' => 'server', 'traffic_class' => $class->value, 'consent_state' => $consent->value,
                    'landing_path' => mb_substr($landingPath, 0, 512), 'referrer_host' => $referrerHost,
                    'utm' => json_encode($link->utm, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
                    'click_ids' => json_encode($clickIds, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
                    'metadata' => '{}', 'occurred_at' => $when,
                ]);
            }

        });
    }

    public function touchpoint(string $touchpointId): ?Touchpoint
    {
        $row = DB::table('tracking_sessions')->where('touchpoint_id', $touchpointId)->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        return new Touchpoint(
            $touchpointId, (string) $row->workspace_id, (string) $row->project_id,
            is_string($row->tracking_link_id) ? $row->tracking_link_id : null,
            (string) $row->visitor_id, (string) $row->id,
            TrafficClass::from((string) $row->traffic_class), ConsentState::from((string) $row->consent_state),
        );
    }

    public function attributionOf(Touchpoint $touchpoint): array
    {
        $row = DB::table('tracking_events')
            ->where('touchpoint_id', $touchpoint->touchpointId)->where('name', TrackingEventName::LinkClick->value)->first();

        if (! $row instanceof stdClass) {
            return ['utm' => [], 'click_ids' => [], 'referrer_host' => null, 'landing_path' => null];
        }

        $strings = static function (mixed $json): array {
            $decoded = is_string($json) ? json_decode($json, true) : [];
            $out = [];

            foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
                if (is_scalar($value)) {
                    $out[(string) $key] = (string) $value;
                }
            }

            return $out;
        };

        return [
            'utm' => $strings($row->utm),
            'click_ids' => $strings($row->click_ids),
            'referrer_host' => is_string($row->referrer_host) ? $row->referrer_host : null,
            'landing_path' => is_string($row->landing_path) ? $row->landing_path : null,
        ];
    }

    public function recordServerEvent(
        string $workspaceId, string $projectId, ?Touchpoint $touchpoint, ?string $trackingLinkId, TrackingEventName $name,
        string $eventId, string $visitorId, ConsentState $consent, TrafficClass $class, DateTimeImmutable $at,
    ): bool {
        return DB::table('tracking_events')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'workspace_id' => $workspaceId, 'project_id' => $projectId,
            'tracking_link_id' => $trackingLinkId, 'event_id' => mb_substr($eventId, 0, 40), 'name' => $name->value,
            'visitor_id' => $visitorId, 'session_id' => $touchpoint?->sessionId, 'touchpoint_id' => $touchpoint?->touchpointId,
            'source' => 'server', 'traffic_class' => $class->value, 'consent_state' => $consent->value,
            'utm' => '{}', 'click_ids' => '{}', 'metadata' => '{}', 'occurred_at' => $at->format('Y-m-d H:i:sP'),
        ]) > 0;
    }

    public function recordBrowserEvent(Touchpoint $touchpoint, TrackingEventName $name, string $eventId, ConsentState $consent, ?string $landingPath, array $metadata, DateTimeImmutable $at): bool
    {
        $when = $at->format('Y-m-d H:i:sP');

        $inserted = DB::table('tracking_events')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'workspace_id' => $touchpoint->workspaceId, 'project_id' => $touchpoint->projectId,
            'tracking_link_id' => $touchpoint->trackingLinkId, 'event_id' => $eventId, 'name' => $name->value,
            'visitor_id' => $touchpoint->visitorId, 'session_id' => $touchpoint->sessionId, 'touchpoint_id' => $touchpoint->touchpointId,
            'source' => 'first_party', 'traffic_class' => $touchpoint->trafficClass->value, 'consent_state' => $consent->value,
            'landing_path' => $landingPath, 'utm' => '{}', 'click_ids' => '{}',
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT), 'occurred_at' => $when,
        ]);

        if ($inserted > 0) {
            DB::table('tracking_sessions')->where('id', $touchpoint->sessionId)->update(['last_seen_at' => $when, 'consent_state' => $consent->value]);
        }

        return $inserted > 0;
    }

    public function countsFor(array $linkIds, bool $includeExcluded = false): array
    {
        if ($linkIds === []) {
            return [];
        }

        $events = DB::table('tracking_events')
            ->whereIn('tracking_link_id', $linkIds)
            ->selectRaw("tracking_link_id,
                count(*) filter (where name = 'link_click' and traffic_class = 'valid') as clicks,
                count(*) filter (where name = 'landing_view' and traffic_class = 'valid') as landing_views,
                count(*) filter (where name = 'link_click' and traffic_class = 'valid' and click_ids <> '{}'::jsonb) as with_click_id,
                count(*) filter (where traffic_class <> 'valid' and name in ('link_click','landing_view')) as excluded,
                max(occurred_at) filter (where name = 'link_click' and traffic_class = 'valid') as last_click")
            ->groupBy('tracking_link_id')
            ->get()->keyBy('tracking_link_id');

        $sessions = DB::table('tracking_sessions')
            ->whereIn('tracking_link_id', $linkIds)->where('traffic_class', 'valid')
            ->selectRaw('tracking_link_id, count(*) as sessions, count(distinct visitor_id) as visitors')
            ->groupBy('tracking_link_id')->get()->keyBy('tracking_link_id');

        $out = [];

        foreach ($linkIds as $id) {
            $e = $events->get($id);
            $s = $sessions->get($id);
            $clicks = $e instanceof stdClass ? (int) $e->clicks : 0;
            $views = $e instanceof stdClass ? (int) $e->landing_views : 0;

            $out[$id] = new LinkCounts(
                clicks: $clicks,
                visitors: $s instanceof stdClass ? (int) $s->visitors : 0,
                sessions: $s instanceof stdClass ? (int) $s->sessions : 0,
                /*
                 * A landing view is reported by the destination page, not recorded by this product. Clicks with no
                 * views is a page that is not reporting, which is not the same fact as nobody arriving.
                 */
                landingViews: $clicks > 0 && $views === 0 ? null : $views,
                landingViewsNote: $clicks > 0 && $views === 0
                    ? 'The destination page has not reported a view to this link. It may not carry the tracking snippet.'
                    : null,
                clickIdCoverage: $clicks > 0 && $e instanceof stdClass ? round((int) $e->with_click_id / $clicks, 4) : null,
                excluded: $e instanceof stdClass ? (int) $e->excluded : 0,
                lastClickAt: $e instanceof stdClass && is_string($e->last_click) ? new DateTimeImmutable($e->last_click) : null,
            );
        }

        return $out;
    }
}
