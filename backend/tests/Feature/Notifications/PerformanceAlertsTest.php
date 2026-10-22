<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

/*
|--------------------------------------------------------------------------
| The alerts, raised from the product's own signals
|--------------------------------------------------------------------------
|
| Over the sandbox sync, with one campaign's figures bent to make a signal: a ROAS drop, a spend spike, an ended
| campaign, a creative wearing out. What is asserted: the right kind with its figures, said once, and told only to
| the people who could have read the thing.
|
*/

/**
 * The two weeks the sandbox covers, as the alert reads them.
 *
 * @return array{current: array{string, string}, previous: array{string, string}}
 */
function alertWeeks(): array
{
    $today = new DateTimeImmutable('today');

    return [
        'current' => [$today->modify('-7 days')->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d')],
        'previous' => [$today->modify('-14 days')->format('Y-m-d'), $today->modify('-8 days')->format('Y-m-d')],
    ];
}

function metaCampaign(): string
{
    return (string) DB::table('campaigns')->where('provider', 'meta')->orderBy('id')->value('id');
}

/** @return list<array<string, mixed>> */
function alertsFor(SyncedWorkspace $w, string $kind): array
{
    return array_values(array_filter(
        test()->actingAs($w->scenario->owner)->getJson('/api/notifications?limit=200')->assertOk()->json('data.notifications'),
        static fn (array $n): bool => $n['kind'] === $kind,
    ));
}

it('raises a ROAS drop with the two figures when this week returned less than last', function (): void {
    $w = SyncedWorkspace::default();
    $campaign = metaCampaign();
    ['current' => $current, 'previous' => $previous] = alertWeeks();

    DB::table('daily_metrics')->where('entity_type', 'campaign')->where('campaign_id', $campaign)->whereBetween('date', $previous)
        ->update(['spend_minor' => 100_000, 'revenue_minor' => 400_000]);
    DB::table('daily_metrics')->where('entity_type', 'campaign')->where('campaign_id', $campaign)->whereBetween('date', $current)
        ->update(['spend_minor' => 100_000, 'revenue_minor' => 200_000]);

    $this->artisan('alerts:raise')->assertSuccessful();
    $alerts = alertsFor($w, 'roas_drop');

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['severity'])->toBe('critical')
        ->and($alerts[0]['payload']['before'])->toEqual(4.0)
        ->and($alerts[0]['payload']['after'])->toEqual(2.0)
        ->and($alerts[0]['payload']['change_ratio'])->toEqual(-0.5)
        ->and($alerts[0]['subject']['type'])->toBe('campaign')
        ->and($alerts[0]['is_waiting'])->toBeTrue();
});

it('raises a spend spike, and says it once however often the hour comes round', function (): void {
    $w = SyncedWorkspace::default();
    $campaign = metaCampaign();
    ['current' => $current, 'previous' => $previous] = alertWeeks();

    DB::table('daily_metrics')->where('entity_type', 'campaign')->where('campaign_id', $campaign)->whereBetween('date', $previous)->update(['spend_minor' => 100_000]);
    DB::table('daily_metrics')->where('entity_type', 'campaign')->where('campaign_id', $campaign)->whereBetween('date', $current)->update(['spend_minor' => 160_000]);

    $this->artisan('alerts:raise')->assertSuccessful();
    $this->artisan('alerts:raise')->assertSuccessful();

    $alerts = alertsFor($w, 'spend_spike');

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['severity'])->toBe('attention');
});

it('says a campaign ended, once, as information rather than a task', function (): void {
    $w = SyncedWorkspace::default();
    DB::table('campaigns')->where('id', metaCampaign())->update(['ends_at' => now()->subHours(2)]);

    $this->artisan('alerts:raise')->assertSuccessful();
    $this->artisan('alerts:raise')->assertSuccessful();
    $alerts = alertsFor($w, 'campaign_ended');

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['severity'])->toBe('info')
        ->and($alerts[0]['is_waiting'])->toBeFalse();
});

it('raises creative fatigue from the creative layer\'s own verdict, with its evidence', function (): void {
    $w = SyncedWorkspace::default();
    $ad = (string) DB::table('ads')->where('provider', 'meta')->orderBy('id')->value('id');
    ['current' => $current, 'previous' => $previous] = alertWeeks();

    DB::table('daily_metrics')->where('entity_type', 'ad')->where('ad_id', $ad)->whereBetween('date', $previous)
        ->update(['impressions' => 50_000, 'reach' => 25_000, 'clicks' => 1_000]);
    DB::table('daily_metrics')->where('entity_type', 'ad')->where('ad_id', $ad)->whereBetween('date', $current)
        ->update(['impressions' => 50_000, 'reach' => 10_000, 'clicks' => 500]);

    $this->artisan('alerts:raise')->assertSuccessful();
    $alerts = alertsFor($w, 'creative_fatigue');

    expect($alerts)->not->toBe([])
        ->and($alerts[0]['payload']['change_ratio'])->toBeLessThan(-0.2)
        ->and($alerts[0]['subject']['type'])->toBe('creative');
});

it('tells only the people who could have read the campaign', function (): void {
    $w = SyncedWorkspace::default();
    $creative = $w->scenario->addMember(SystemRole::Creative);
    $buyer = $w->scenario->addMember(SystemRole::MediaBuyer);
    DB::table('campaigns')->where('id', metaCampaign())->update(['ends_at' => now()->subHours(2)]);

    $this->artisan('alerts:raise')->assertSuccessful();

    expect(DB::table('notifications')->where('kind', 'campaign_ended')->where('user_id', $creative->getKey())->count())->toBe(0)
        ->and(DB::table('notifications')->where('kind', 'campaign_ended')->where('user_id', $buyer->getKey())->count())->toBe(0)
        ->and(DB::table('notifications')->where('kind', 'campaign_ended')->where('user_id', $w->scenario->owner->getKey())->count())->toBe(1);
});
