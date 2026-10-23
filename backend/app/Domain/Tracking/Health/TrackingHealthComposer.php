<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Health;

/**
 * Turns what the product knows into a state per piece of tracking.
 *
 * The rules, each of which is a refusal to flatter:
 *
 * - **Only evidence makes a state good.** First-party events are `LIVE` when one arrived in the last day, `STALE` when the
 *   newest is older than a week, `PARTIAL` when clicks arrive and no landing page reports (the snippet is missing).
 * - **A provider pixel is never "green".** digitalSmart does not observe a pixel fire in a visitor's browser, so a browser
 *   channel is `NOT_CONFIGURED` with the way to verify it, whatever else is connected.
 * - **A provider's server channel needs a connection and, from part E, a mapping.** No connection is
 *   `AWAITING_CREDENTIALS`; a sandbox connection is `SANDBOX`; a provider that publishes no API for it is `UNSUPPORTED`;
 *   everything else is `NOT_CONFIGURED` until an event is mapped and dispatched.
 * - **Deduplication cannot be claimed** without a provider confirming receipt of both copies, so it is `NOT_CONFIGURED`.
 * - **Click-id coverage and consent are shares**, `PARTIAL` below the stated lines and `NOT_CONFIGURED` with no traffic.
 *
 * @phpstan-type Facts array{
 *   links_active: int,
 *   events_24h: int,
 *   last_event_at: ?int,
 *   clicks_7d: int,
 *   landing_views_7d: int,
 *   clicks_with_click_id_7d: int,
 *   leads_7d: int,
 *   leads_with_evidence_7d: int,
 *   consent: array{unknown: int, granted: int, denied: int, not_required: int},
 *   ga4: ?array{last_synced_at: ?int, last_event_at: ?int},
 *   gtm_containers: int,
 * }
 * @phpstan-type Connection array{connected: bool, sandbox: bool, unsupported: bool}
 */
final class TrackingHealthComposer
{
    public const int COVERAGE_LINE_PERCENT = 70;

    /** Providers whose conversions a server channel could carry, in the owner's order. */
    private const array SERVER_PROVIDERS = [
        'snapchat' => 'Snapchat', 'tiktok' => 'TikTok', 'meta' => 'Meta', 'linkedin' => 'LinkedIn',
    ];

    /**
     * @param  Facts  $f
     * @param  array<string, Connection>  $connections  by provider slug; a missing provider is not connected
     * @return list<HealthCheck>
     */
    public static function compose(array $f, array $connections, int $now): array
    {
        $checks = [self::firstParty($f, $now)];

        $checks[] = self::gtm($f, $connections);
        $checks[] = self::ga4($f, $connections, $now);

        foreach (self::SERVER_PROVIDERS as $slug => $label) {
            $checks[] = new HealthCheck(
                $slug.'_browser', 'browser', TrackingHealthState::NotConfigured,
                sprintf('%s’s pixel firing in the visitor’s browser is not observed by digitalSmart. Verify it with a test event in %s’s own tool.', $label, $label),
            );
            $checks[] = self::server($slug, $label, $connections[$slug] ?? null);
        }

        $checks[] = self::googleConversion($f, $connections['google_ads'] ?? null);
        $checks[] = self::unsupportedServer('x', 'X', $connections['x'] ?? null);
        $checks[] = self::unsupportedServer('openai', 'ChatGPT / OpenAI Ads', $connections['openai'] ?? null);
        $checks[] = self::clickIds($f);
        $checks[] = new HealthCheck(
            'deduplication', 'quality', TrackingHealthState::NotConfigured,
            'Browser and server copies of a conversion are not sent yet, so there is nothing to de-duplicate. Success is claimed only when a provider confirms it.',
        );
        $checks[] = self::attribution($f);
        $checks[] = self::consent($f);

        return $checks;
    }

    /**
     * @param  list<HealthCheck>  $checks
     * @return array{needs_attention: int, by_state: array<string, int>}
     */
    public static function summary(array $checks): array
    {
        $byState = [];
        $attention = 0;

        foreach ($checks as $c) {
            $byState[$c->state->value] = ($byState[$c->state->value] ?? 0) + 1;
            $attention += $c->state->needsAttention() ? 1 : 0;
        }

        return ['needs_attention' => $attention, 'by_state' => $byState];
    }

    /** @param Facts $f */
    private static function firstParty(array $f, int $now): HealthCheck
    {
        $evidence = ['active_links' => $f['links_active'], 'events_24h' => $f['events_24h'], 'clicks_7d' => $f['clicks_7d'], 'landing_views_7d' => $f['landing_views_7d']];

        if ($f['last_event_at'] === null) {
            return new HealthCheck(
                'first_party', 'source', TrackingHealthState::NotConfigured,
                $f['links_active'] === 0 ? 'No tracking link exists yet.' : 'A tracking link exists but nobody has followed it yet.',
                $evidence,
            );
        }

        if ($now - $f['last_event_at'] > 7 * 86_400) {
            return new HealthCheck('first_party', 'source', TrackingHealthState::Stale, 'No first-party event in the last seven days.', $evidence);
        }

        if ($f['clicks_7d'] > 0 && $f['landing_views_7d'] === 0) {
            return new HealthCheck(
                'first_party', 'source', TrackingHealthState::Partial,
                'Clicks are recorded but no landing page has reported a view. The destination page probably does not carry the tracking snippet.',
                $evidence,
            );
        }

        return $f['events_24h'] > 0
            ? new HealthCheck('first_party', 'source', TrackingHealthState::Live, 'First-party events arrived in the last 24 hours.', $evidence)
            : new HealthCheck('first_party', 'source', TrackingHealthState::Synced, 'First-party events arrived this week, none in the last 24 hours.', $evidence);
    }

    /**
     * @param  Facts  $f
     * @param  array<string, Connection>  $connections
     */
    private static function gtm(array $f, array $connections): HealthCheck
    {
        $evidence = ['containers_registered' => $f['gtm_containers']];
        $conn = $connections['google_tag_manager'] ?? null;

        if ($f['gtm_containers'] === 0) {
            return new HealthCheck('gtm', 'destination', TrackingHealthState::NotConfigured, 'No Tag Manager container is registered for this client. The data layer snippet works without one.', $evidence);
        }

        if ($conn === null || ! $conn['connected']) {
            return new HealthCheck('gtm', 'destination', TrackingHealthState::AwaitingCredentials, 'A container is registered but Tag Manager is not connected.', $evidence);
        }

        return new HealthCheck(
            'gtm', 'destination', $conn['sandbox'] ? TrackingHealthState::Sandbox : TrackingHealthState::Synced,
            'A container is registered and connected. Whether its published version fires the data layer events is shown in Tracking → Overview.',
            $evidence,
        );
    }

    /**
     * @param  Facts  $f
     * @param  array<string, Connection>  $connections
     */
    private static function ga4(array $f, array $connections, int $now): HealthCheck
    {
        $ga = $f['ga4'];

        if ($ga === null) {
            return new HealthCheck('ga4', 'destination', TrackingHealthState::NotConfigured, 'No Google Analytics property is registered for this client.');
        }

        $conn = $connections['google_analytics'] ?? null;
        $evidence = ['last_synced_at' => $ga['last_synced_at'], 'last_event_at' => $ga['last_event_at']];

        if ($conn === null || ! $conn['connected']) {
            return new HealthCheck('ga4', 'destination', TrackingHealthState::AwaitingCredentials, 'A property is registered but Google Analytics is not connected.', $evidence);
        }

        if ($conn['sandbox']) {
            return new HealthCheck('ga4', 'destination', TrackingHealthState::Sandbox, 'Read from sandbox fixtures, not a live property.', $evidence);
        }

        if ($ga['last_synced_at'] === null || $now - $ga['last_synced_at'] > 36 * 3600) {
            return new HealthCheck('ga4', 'destination', TrackingHealthState::Stale, 'The property has not been read in the last 36 hours. digitalSmart reads GA4; it does not write to it.', $evidence);
        }

        return new HealthCheck('ga4', 'destination', TrackingHealthState::Synced, 'The property was read recently. digitalSmart reads GA4 and does not write to it; events reach it through Tag Manager.', $evidence);
    }

    /** @param Connection|null $conn */
    private static function server(string $slug, string $label, ?array $conn): HealthCheck
    {
        if ($conn !== null && $conn['unsupported']) {
            return new HealthCheck($slug.'_server', 'server', TrackingHealthState::Unsupported, sprintf('%s publishes no server conversion API digitalSmart can use.', $label));
        }

        if ($conn === null || ! $conn['connected']) {
            return new HealthCheck($slug.'_server', 'server', TrackingHealthState::AwaitingCredentials, sprintf('%s is not connected, so nothing can be sent to it.', $label));
        }

        return $conn['sandbox']
            ? new HealthCheck($slug.'_server', 'server', TrackingHealthState::Sandbox, sprintf('%s is connected with sandbox credentials. Nothing is sent.', $label))
            : new HealthCheck($slug.'_server', 'server', TrackingHealthState::NotConfigured, sprintf('%s is connected but no event is mapped to it yet, so nothing is sent.', $label));
    }

    /**
     * @param  Facts  $f
     * @param  Connection|null  $conn
     */
    private static function googleConversion(array $f, ?array $conn): HealthCheck
    {
        $evidence = ['clicks_7d' => $f['clicks_7d'], 'clicks_with_click_id_7d' => $f['clicks_with_click_id_7d']];

        if ($conn === null || ! $conn['connected']) {
            return new HealthCheck('google_conversion', 'server', TrackingHealthState::AwaitingCredentials, 'Google Ads is not connected, so offline conversions cannot be uploaded.', $evidence);
        }

        return $conn['sandbox']
            ? new HealthCheck('google_conversion', 'server', TrackingHealthState::Sandbox, 'Google Ads is connected with sandbox credentials. Nothing is uploaded.', $evidence)
            : new HealthCheck('google_conversion', 'server', TrackingHealthState::NotConfigured, 'Google Ads is connected but no conversion action is mapped, so nothing is uploaded.', $evidence);
    }

    /** @param Connection|null $conn */
    private static function unsupportedServer(string $slug, string $label, ?array $conn): HealthCheck
    {
        return new HealthCheck(
            $slug.'_server', 'server', TrackingHealthState::Unsupported,
            $conn !== null && $conn['connected']
                ? sprintf('%s is connected, but no server conversion path is documented that digitalSmart implements.', $label)
                : sprintf('No server conversion path is documented for %s that digitalSmart implements.', $label),
        );
    }

    /** @param Facts $f */
    private static function clickIds(array $f): HealthCheck
    {
        if ($f['clicks_7d'] === 0) {
            return new HealthCheck('click_ids', 'quality', TrackingHealthState::NotConfigured, 'No clicks in the last seven days to measure.');
        }

        $share = (int) round($f['clicks_with_click_id_7d'] / $f['clicks_7d'] * 100);
        $evidence = ['clicks_7d' => $f['clicks_7d'], 'with_click_id_7d' => $f['clicks_with_click_id_7d'], 'percent' => $share];

        return $share >= self::COVERAGE_LINE_PERCENT
            ? new HealthCheck('click_ids', 'quality', TrackingHealthState::Live, sprintf('%d%% of clicks carried a documented click identifier.', $share), $evidence)
            : new HealthCheck('click_ids', 'quality', TrackingHealthState::Partial, sprintf('Only %d%% of clicks carried a documented click identifier. Organic and some placements carry none; paid traffic without auto-tagging loses attribution.', $share), $evidence);
    }

    /** @param Facts $f */
    private static function attribution(array $f): HealthCheck
    {
        if ($f['leads_7d'] === 0) {
            return new HealthCheck('attribution', 'quality', TrackingHealthState::NotConfigured, 'No leads in the last seven days to attribute.');
        }

        $share = (int) round($f['leads_with_evidence_7d'] / $f['leads_7d'] * 100);
        $evidence = ['leads_7d' => $f['leads_7d'], 'with_evidence_7d' => $f['leads_with_evidence_7d'], 'percent' => $share];

        return $share >= self::COVERAGE_LINE_PERCENT
            ? new HealthCheck('attribution', 'quality', TrackingHealthState::Attributed, sprintf('%d%% of leads carry attribution evidence. Confidence follows the strength of that evidence.', $share), $evidence)
            : new HealthCheck('attribution', 'quality', TrackingHealthState::Partial, sprintf('Only %d%% of leads carry attribution evidence; treat attributed results as a sample.', $share), $evidence);
    }

    /** @param Facts $f */
    private static function consent(array $f): HealthCheck
    {
        $c = $f['consent'];
        $total = array_sum($c);

        if ($total === 0) {
            return new HealthCheck('consent', 'quality', TrackingHealthState::NotConfigured, 'No visits in the last seven days to read consent from. A project consent policy arrives with the dispatch settings.');
        }

        $known = $total - $c['unknown'];
        $evidence = [...$c, 'sessions_7d' => $total];

        return $known === 0
            ? new HealthCheck('consent', 'quality', TrackingHealthState::Partial, 'Every visit has an unknown consent state, so no marketing destination may be sent anything.', $evidence)
            : new HealthCheck('consent', 'quality', $c['unknown'] > 0 ? TrackingHealthState::Partial : TrackingHealthState::Live, sprintf('%d of %d visits carry a consent state.', $known, $total), $evidence);
    }
}
