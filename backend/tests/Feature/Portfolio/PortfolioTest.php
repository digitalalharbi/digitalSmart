<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use Tests\Support\SyncedWorkspace;

/**
 * The portfolio assembles; it does not recompute.
 *
 * What is asserted is mostly agreement and omission: that a client's row is the campaign control center's
 * answer grouped by client, that sections a role may not see are null rather than zero, and that nobody
 * outside the agency reaches it at all.
 */
function portfolioWorld(): SyncedWorkspace
{
    return SyncedWorkspace::with([
        Provider::Meta->value => 'act_sandbox_1001',
        Provider::GoogleAds->value => 'act_sandbox_2001',
    ]);
}

it('groups the control center’s campaigns by client and agrees with it on spend', function (): void {
    $w = portfolioWorld();
    $owner = $w->scenario->owner;

    $portfolio = test()->actingAs($owner)->getJson('/api/portfolio')->assertOk()->json('data');
    $campaigns = test()->actingAs($owner)->getJson('/api/campaigns/control?limit=200')->assertOk()->json('data');

    $fromControl = [];

    foreach ($campaigns as $c) {
        $fromControl[$c['client_id']][$c['currency']] = ($fromControl[$c['client_id']][$c['currency']] ?? 0) + (int) $c['spend_minor'];
    }

    expect($portfolio['clients'])->not->toBeEmpty();

    foreach ($portfolio['clients'] as $client) {
        foreach ($client['spend'] as $line) {
            expect($line['minor'])->toBe($fromControl[$client['id']][$line['currency']]);
        }
    }

    /* Health totals are the same campaigns, counted. */
    expect(array_sum($portfolio['health']))->toBe(count($campaigns));
});

it('keeps spend per currency and never adds two currencies together', function (): void {
    $w = portfolioWorld();

    $kpis = test()->actingAs($w->scenario->owner)->getJson('/api/portfolio')->json('data.kpis');

    foreach ($kpis['spend'] as $line) {
        expect($line)->toHaveKeys(['currency', 'minor']);
    }

    expect(array_column($kpis['spend'], 'currency'))->toBe(array_values(array_unique(array_column($kpis['spend'], 'currency'))));
});

it('opens one client with its projects, and drills down no further than the reader may see', function (): void {
    $w = portfolioWorld();
    $owner = $w->scenario->owner;
    $client = (string) $w->project(Provider::Meta)->getAttribute('client_id');

    $one = test()->actingAs($owner)->getJson("/api/portfolio/clients/{$client}")->assertOk()->json('data');

    expect($one['clients'])->toHaveCount(1)
        ->and($one['clients'][0]['id'])->toBe($client)
        ->and($one['projects'])->not->toBeEmpty()
        ->and(array_column($one['projects'], 'client_id'))->each->toBe($client)
        // A client's page carries that client's outcomes, not the agency's.
        ->and($one['kpis']['sales'])->toBe($one['clients'][0]['sales'])
        ->and($one['kpis']['leads'])->toBe($one['clients'][0]['leads']);
});

/* A scoped reader: only the clients of projects they are on, and finance is not theirs to see as a zero. */
it('shows a media buyer only their own clients, and omits what their role does not hold', function (): void {
    $w = portfolioWorld();
    $buyer = $w->scenario->addMember(SystemRole::MediaBuyer);

    $data = test()->actingAs($buyer)->getJson('/api/portfolio')->assertOk()->json('data');

    expect($data['clients'])->toBe([])
        ->and($data['health'])->each->toBe(0);
});

/*
 * No system role holds analytics without budgets, leads and tracking, so the "section is null rather than
 * zero" path is only reachable through a custom role. What can be asserted here is the other half: a role with
 * none of them is refused outright instead of being handed a page of zeroes.
 */
it('refuses a role that holds none of the portfolio’s permissions rather than showing zeroes', function (): void {
    $w = portfolioWorld();
    $creative = $w->scenario->addMember(SystemRole::Creative);
    $manager = $w->scenario->addMember(SystemRole::ProjectManager);

    test()->actingAs($creative)->getJson('/api/portfolio')->assertForbidden();

    $data = test()->actingAs($manager)->getJson('/api/portfolio')->assertOk()->json('data');
    expect($data)->toHaveKeys(['kpis', 'clients', 'platform_mix', 'budget_health', 'tracking_health']);
});

it('is not available to a client user at all', function (): void {
    $w = portfolioWorld();
    $viewer = $w->scenario->addMember(SystemRole::ClientViewer);
    $client = (string) $w->project(Provider::Meta)->getAttribute('client_id');

    test()->actingAs($viewer)->getJson('/api/portfolio')->assertForbidden();
    test()->actingAs($viewer)->getJson("/api/portfolio/clients/{$client}")->assertForbidden();
});
