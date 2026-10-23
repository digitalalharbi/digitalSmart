<?php

declare(strict_types=1);

use App\Domain\Tracking\DataLayerMap;
use App\Domain\Tracking\Exception\InvalidDataLayerMap;
use App\Domain\Tracking\Health\TrackingHealthComposer;
use App\Domain\Tracking\Health\TrackingHealthState;

const NOW = 1_800_000_000;

/**
 * @param  array<string, mixed>  $over
 * @return array{links_active: int, events_24h: int, last_event_at: ?int, clicks_7d: int, landing_views_7d: int, clicks_with_click_id_7d: int, leads_7d: int, leads_with_evidence_7d: int, consent: array{unknown: int, granted: int, denied: int, not_required: int}, ga4: ?array{last_synced_at: ?int, last_event_at: ?int}, gtm_containers: int}
 */
function facts(array $over = []): array
{
    $m = array_replace([
        'links_active' => 1, 'events_24h' => 5, 'last_event_at' => NOW - 100, 'clicks_7d' => 10, 'landing_views_7d' => 8,
        'clicks_with_click_id_7d' => 9, 'leads_7d' => 4, 'leads_with_evidence_7d' => 4,
        'consent' => ['unknown' => 0, 'granted' => 8, 'denied' => 1, 'not_required' => 0],
        'ga4' => null, 'gtm_containers' => 0,
    ], $over);
    $consent = is_array($m['consent']) ? $m['consent'] : [];
    $ga = is_array($m['ga4']) ? $m['ga4'] : null;
    $nullableInt = static fn (mixed $v): ?int => $v === null ? null : (int) $v;

    return [
        'links_active' => (int) $m['links_active'],
        'events_24h' => (int) $m['events_24h'],
        'last_event_at' => $nullableInt($m['last_event_at']),
        'clicks_7d' => (int) $m['clicks_7d'],
        'landing_views_7d' => (int) $m['landing_views_7d'],
        'clicks_with_click_id_7d' => (int) $m['clicks_with_click_id_7d'],
        'leads_7d' => (int) $m['leads_7d'],
        'leads_with_evidence_7d' => (int) $m['leads_with_evidence_7d'],
        'consent' => ['unknown' => (int) ($consent['unknown'] ?? 0), 'granted' => (int) ($consent['granted'] ?? 0), 'denied' => (int) ($consent['denied'] ?? 0), 'not_required' => (int) ($consent['not_required'] ?? 0)],
        'ga4' => $ga === null ? null : ['last_synced_at' => $nullableInt($ga['last_synced_at'] ?? null), 'last_event_at' => $nullableInt($ga['last_event_at'] ?? null)],
        'gtm_containers' => (int) $m['gtm_containers'],
    ];
}

/**
 * @param  array<string, mixed>  $over
 * @param  array<string, array{connected: bool, sandbox: bool, unsupported: bool}>  $connections
 * @return array<string, array<string, mixed>>
 */
function checksOf(array $over, array $connections = []): array
{
    $out = [];

    foreach (TrackingHealthComposer::compose(facts($over), $connections, NOW) as $c) {
        $out[$c->key] = $c->toArray();
    }

    return $out;
}

it('earns a good first-party state from evidence and from nothing else', function (): void {
    expect(checksOf([])['first_party']['state'])->toBe('LIVE')
        ->and(checksOf(['events_24h' => 0])['first_party']['state'])->toBe('SYNCED')
        ->and(checksOf(['last_event_at' => NOW - 9 * 86_400])['first_party']['state'])->toBe('STALE')
        ->and(checksOf(['landing_views_7d' => 0])['first_party']['state'])->toBe('PARTIAL')
        ->and(checksOf(['links_active' => 0, 'last_event_at' => null, 'events_24h' => 0, 'clicks_7d' => 0])['first_party']['state'])->toBe('NOT_CONFIGURED')
        ->and(checksOf(['last_event_at' => null, 'events_24h' => 0, 'clicks_7d' => 0])['first_party']['detail'])->toContain('nobody has followed');
});

it('never reports a provider pixel as working, and reports a server channel only as far as the connection goes', function (): void {
    $none = checksOf([]);
    $sandbox = checksOf([], ['meta' => ['connected' => true, 'sandbox' => true, 'unsupported' => false]]);
    $live = checksOf([], ['meta' => ['connected' => true, 'sandbox' => false, 'unsupported' => false]]);

    expect($none['meta_browser']['state'])->toBe('NOT_CONFIGURED')
        ->and($none['meta_server']['state'])->toBe('AWAITING_CREDENTIALS')
        ->and($sandbox['meta_server']['state'])->toBe('SANDBOX')
        ->and($live['meta_server']['state'])->toBe('NOT_CONFIGURED')
        ->and($live['meta_browser']['state'])->toBe('NOT_CONFIGURED')
        ->and($none['x_server']['state'])->toBe('UNSUPPORTED')
        ->and($none['openai_server']['state'])->toBe('UNSUPPORTED')
        ->and($none['deduplication']['state'])->toBe('NOT_CONFIGURED');
});

it('reads GA4 and Tag Manager from what is registered and connected, and says GA4 is read-only', function (): void {
    $on = ['connected' => true, 'sandbox' => false, 'unsupported' => false];
    $ga = ['last_synced_at' => NOW - 3600, 'last_event_at' => NOW - 3600];

    expect(checksOf([])['ga4']['state'])->toBe('NOT_CONFIGURED')
        ->and(checksOf(['ga4' => $ga])['ga4']['state'])->toBe('AWAITING_CREDENTIALS')
        ->and(checksOf(['ga4' => $ga], ['google_analytics' => $on])['ga4']['state'])->toBe('SYNCED')
        ->and(checksOf(['ga4' => [...$ga, 'last_synced_at' => NOW - 50 * 3600]], ['google_analytics' => $on])['ga4']['state'])->toBe('STALE')
        ->and(checksOf(['ga4' => $ga], ['google_analytics' => [...$on, 'sandbox' => true]])['ga4']['state'])->toBe('SANDBOX')
        ->and(checksOf(['ga4' => $ga], ['google_analytics' => $on])['ga4']['detail'])->toContain('does not write')
        ->and(checksOf(['gtm_containers' => 1], ['google_tag_manager' => $on])['gtm']['state'])->toBe('SYNCED');
});

it('treats click identifiers, attribution and consent as shares with a stated line', function (): void {
    $unknown = ['unknown' => 5, 'granted' => 0, 'denied' => 0, 'not_required' => 0];
    $granted = ['unknown' => 0, 'granted' => 5, 'denied' => 0, 'not_required' => 0];

    expect(checksOf([])['click_ids']['state'])->toBe('LIVE')
        ->and(checksOf(['clicks_with_click_id_7d' => 2])['click_ids']['state'])->toBe('PARTIAL')
        ->and(checksOf(['clicks_7d' => 0])['click_ids']['state'])->toBe('NOT_CONFIGURED')
        ->and(checksOf([])['attribution']['state'])->toBe('ATTRIBUTED')
        ->and(checksOf(['leads_with_evidence_7d' => 1])['attribution']['state'])->toBe('PARTIAL')
        ->and(checksOf(['leads_7d' => 0, 'leads_with_evidence_7d' => 0])['attribution']['state'])->toBe('NOT_CONFIGURED')
        ->and(checksOf(['consent' => $unknown])['consent']['state'])->toBe('PARTIAL')
        ->and(checksOf(['consent' => $granted])['consent']['state'])->toBe('LIVE');
});

it('summarises what needs attention without counting a missing credential as a fault', function (): void {
    $checks = TrackingHealthComposer::compose(facts(['landing_views_7d' => 0]), [], NOW);
    $summary = TrackingHealthComposer::summary($checks);

    expect($summary['by_state'])->toHaveKey('PARTIAL')->and($summary['needs_attention'])->toBeGreaterThan(0)
        ->and(TrackingHealthState::AwaitingCredentials->needsAttention())->toBeFalse()
        ->and(TrackingHealthState::Unsupported->needsAttention())->toBeFalse();
});

it('builds data layer names from a prefix and refuses ones that would break it', function (): void {
    expect(DataLayerMap::defaults()['form_submit'])->toBe('ds_form_submit')
        ->and(DataLayerMap::resolve(['form_submit' => 'client_lead'])['form_submit'])->toBe('client_lead')
        ->and(DataLayerMap::resolve([])['landing_view'])->toBe('ds_landing_view');

    foreach ([['lead_created' => 'x_y'], ['form_submit' => 'gtm.js'], ['form_submit' => 'bad name'], ['form_submit' => '1abc']] as $bad) {
        expect(fn () => DataLayerMap::resolve($bad))->toThrow(InvalidDataLayerMap::class);
    }
});

it('writes a snippet that reports behaviour only and never reads what a visitor types', function (): void {
    $snippet = DataLayerMap::snippet('https://app.example.com/api', DataLayerMap::defaults());

    expect($snippet)->toContain('https://app.example.com/api/public/track')
        ->and($snippet)->toContain('ds_landing_view')
        ->and($snippet)->toContain('sendBeacon')
        ->and($snippet)->not->toContain('addEventListener')
        ->and($snippet)->not->toContain('.value')
        ->and($snippet)->not->toContain('localStorage');
});
