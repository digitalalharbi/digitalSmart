<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

const BIND_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';

/** @return array{w: SyncedWorkspace, project: string, campaign: string, group: string, ad: string, creative: ?string} */
function bindWorld(): array
{
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $project = (string) $w->project(Provider::Meta)->getKey();
    $ad = DB::table('ads')->where('workspace_id', $w->workspaceId())->whereNotNull('creative_id')->first()
        ?? DB::table('ads')->where('workspace_id', $w->workspaceId())->first();
    assert($ad instanceof stdClass);

    return [
        'w' => $w, 'project' => $project, 'campaign' => (string) $ad->campaign_id, 'group' => (string) $ad->ad_group_id,
        'ad' => (string) $ad->id, 'creative' => is_string($ad->creative_id) ? $ad->creative_id : null,
    ];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function bindBody(string $project, string $name, array $extra = []): array
{
    return ['project_id' => $project, 'name' => $name, 'destination_url' => 'https://shop.example.com/offer', ...$extra];
}

function bindClick(object $test, string $short, int $times = 1): void
{
    for ($i = 0; $i < $times; $i++) {
        $test->withHeaders(['User-Agent' => BIND_UA])->get('/api/r/'.basename($short))->assertRedirect();
    }
}

/** Spend the campaign reported over the default window, straight from the fact table. */
function bindCampaignSpend(string $campaign): int
{
    return (int) DB::table('daily_metrics')->where('entity_type', 'campaign')->where('entity_id', $campaign)->sum('spend_minor');
}

it('binds a link to a campaign and takes its platform and account from the campaign', function (): void {
    $b = bindWorld();
    $link = test()->actingAs($b['w']->scenario->owner)->postJson('/api/tracking/links', bindBody($b['project'], 'Bound to campaign', ['campaign_id' => $b['campaign']]))
        ->assertCreated()->json('data');

    expect($link['binding']['level'])->toBe('campaign')
        ->and($link['binding']['campaign_id'])->toBe($b['campaign'])
        ->and($link['binding']['ad_group_id'])->toBeNull()
        ->and($link['platform'])->toBe('meta')
        ->and($link['binding']['external_account_id'])->toBe((string) $b['w']->account(Provider::Meta)->getKey())
        ->and($link['campaign'])->toBe((string) DB::table('campaigns')->where('id', $b['campaign'])->value('name'));
});

it('binds at the ad, and the ad decides its ad group, campaign and creative', function (): void {
    $b = bindWorld();
    $link = test()->actingAs($b['w']->scenario->owner)->postJson('/api/tracking/links', bindBody($b['project'], 'Bound to ad', ['ad_id' => $b['ad']]))
        ->assertCreated()->json('data');

    expect($link['binding']['level'])->toBe('ad')
        ->and($link['binding']['ad_group_id'])->toBe($b['group'])
        ->and($link['binding']['campaign_id'])->toBe($b['campaign'])
        ->and($link['binding']['creative_id'])->toBe($b['creative']);
});

it('refuses a hierarchy that does not hold together', function (): void {
    $b = bindWorld();
    $owner = $b['w']->scenario->owner;
    $otherCampaign = (string) DB::table('campaigns')->where('id', '!=', $b['campaign'])->value('id');

    test()->actingAs($owner)->postJson('/api/tracking/links', bindBody($b['project'], 'x1', ['ad_id' => $b['ad'], 'campaign_id' => $otherCampaign]))->assertUnprocessable();
    test()->actingAs($owner)->postJson('/api/tracking/links', bindBody($b['project'], 'x2', ['ad_group_id' => $b['group'], 'campaign_id' => $otherCampaign]))->assertUnprocessable();
    test()->actingAs($owner)->postJson('/api/tracking/links', bindBody($b['project'], 'x3', ['campaign_id' => $b['campaign'], 'platform' => 'tiktok']))->assertUnprocessable();
    test()->actingAs($owner)->postJson('/api/tracking/links', bindBody($b['project'], 'x4', ['campaign_id' => $b['campaign'], 'creative_id' => (string) DB::table('creatives')->value('id')]))->assertUnprocessable();
    test()->actingAs($owner)->postJson('/api/tracking/links', bindBody($b['project'], 'x5', ['campaign_id' => '0191d0e4-0000-7000-8000-000000000000']))->assertUnprocessable();
});

it('refuses a campaign the project cannot read, or one assigned to another project', function (): void {
    $b = bindWorld();
    $owner = $b['w']->scenario->owner;
    $client = App\Infrastructure\Persistence\Eloquent\Model\Client::query()->findOrFail((string) $b['w']->project(Provider::Meta)->getAttribute('client_id'));
    assert($client instanceof App\Infrastructure\Persistence\Eloquent\Model\Client);
    $second = App\Infrastructure\Persistence\Eloquent\Model\Project::factory()->forClient($client)->create();

    /* An account that belongs to the first project only. */
    test()->actingAs($owner)->postJson('/api/tracking/links', bindBody((string) $second->getKey(), 'Wrong project', ['campaign_id' => $b['campaign']]))
        ->assertUnprocessable();

    DB::table('campaigns')->where('id', $b['campaign'])->update(['project_id' => (string) $second->getKey()]);
    test()->actingAs($owner)->postJson('/api/tracking/links', bindBody($b['project'], 'Taken', ['campaign_id' => $b['campaign']]))->assertUnprocessable();
});

it('lists only what the project may bind, one level at a time', function (): void {
    $b = bindWorld();
    $owner = $b['w']->scenario->owner;
    $get = static fn (string $level, ?string $parent = null): array => test()->actingAs($owner)
        ->getJson('/api/tracking/binding-options?project_id='.$b['project'].'&level='.$level.($parent === null ? '' : '&parent_id='.$parent))
        ->assertOk()->json('data');

    $account = (string) $b['w']->account(Provider::Meta)->getKey();

    expect(array_column($get('accounts'), 'id'))->toContain($account)
        ->and(array_column($get('campaigns', $account), 'id'))->toContain($b['campaign'])
        ->and(array_column($get('ad_groups', $b['campaign']), 'id'))->toContain($b['group'])
        ->and(array_column($get('ads', $b['group']), 'id'))->toContain($b['ad'])
        ->and($get('ads'))->toBe([]);
});

it('credits a link with its campaign spend directly when nothing else competes, and derives the costs from it', function (): void {
    $b = bindWorld();
    $owner = $b['w']->scenario->owner;
    $link = test()->actingAs($owner)->postJson('/api/tracking/links', bindBody($b['project'], 'Alone', ['campaign_id' => $b['campaign']]))->json('data');
    bindClick(test(), $link['short_url'], 4);

    $perf = test()->actingAs($owner)->getJson('/api/tracking/links/'.$link['id'].'/performance')->assertOk()->json('data');
    $spend = bindCampaignSpend($b['campaign']);

    expect($perf['spend']['basis'])->toBe('DIRECT')
        ->and($perf['spend']['level'])->toBe('campaign')
        ->and($perf['spend']['minor'])->toBe($spend)
        ->and($perf['costs']['cpc']['minor'])->toBe(intdiv($spend + 2, 4))
        ->and($perf['costs']['cpl']['minor'])->toBeNull()
        ->and($perf['costs']['cpl']['reason'])->toContain('No leads yet');
});

it('never gives every link on one campaign the whole campaign spend', function (): void {
    $b = bindWorld();
    $owner = $b['w']->scenario->owner;
    $a = test()->actingAs($owner)->postJson('/api/tracking/links', bindBody($b['project'], 'Twin A', ['campaign_id' => $b['campaign']]))->json('data');
    $c = test()->actingAs($owner)->postJson('/api/tracking/links', bindBody($b['project'], 'Twin B', ['campaign_id' => $b['campaign']]))->json('data');
    bindClick(test(), $a['short_url'], 3);
    bindClick(test(), $c['short_url'], 1);

    foreach ([$a, $c] as $link) {
        $perf = test()->actingAs($owner)->getJson('/api/tracking/links/'.$link['id'].'/performance')->json('data');

        expect($perf['spend']['basis'])->toBe('UNAVAILABLE')->and($perf['spend']['minor'])->toBeNull()
            ->and($perf['spend']['reason'])->toContain('same campaign')
            ->and($perf['costs']['cpc']['minor'])->toBeNull();
    }

    /* Only when asked, and then labelled as an allocation with its method and a low confidence. */
    $one = test()->actingAs($owner)->getJson('/api/tracking/links/'.$a['id'].'/performance?allocate=clicks')->json('data.spend');
    $two = test()->actingAs($owner)->getJson('/api/tracking/links/'.$c['id'].'/performance?allocate=clicks')->json('data.spend');

    expect($one['basis'])->toBe('ALLOCATED')->and($one['allocation']['method'])->toBe('tracked_clicks')
        ->and($one['allocation']['confidence'])->toBe('low')->and($one['allocation']['share'])->toBe(0.75)
        ->and(abs($one['minor'] + $two['minor'] - bindCampaignSpend($b['campaign'])))->toBeLessThanOrEqual(1);
});

it('does not credit a campaign link with spend that a link inside the campaign shares', function (): void {
    $b = bindWorld();
    $owner = $b['w']->scenario->owner;
    $campaignLink = test()->actingAs($owner)->postJson('/api/tracking/links', bindBody($b['project'], 'Campaign wide', ['campaign_id' => $b['campaign']]))->json('data');
    $adLink = test()->actingAs($owner)->postJson('/api/tracking/links', bindBody($b['project'], 'One ad', ['ad_id' => $b['ad']]))->json('data');
    bindClick(test(), $campaignLink['short_url'], 2);
    bindClick(test(), $adLink['short_url'], 2);

    $wide = test()->actingAs($owner)->getJson('/api/tracking/links/'.$campaignLink['id'].'/performance')->json('data.spend');
    $ad = test()->actingAs($owner)->getJson('/api/tracking/links/'.$adLink['id'].'/performance')->json('data.spend');

    expect($wide['basis'])->toBe('UNAVAILABLE')->and($wide['reason'])->toContain('inside')
        ->and($ad['basis'])->toBe('DIRECT')->and($ad['level'])->toBe('ad')
        ->and($ad['minor'])->toBe((int) DB::table('daily_metrics')->where('entity_type', 'ad')->where('entity_id', $b['ad'])->sum('spend_minor'));
});

it('says an unbound link has no spend rather than showing zero', function (): void {
    $b = bindWorld();
    $owner = $b['w']->scenario->owner;
    $link = test()->actingAs($owner)->postJson('/api/tracking/links', bindBody($b['project'], 'Unbound'))->json('data');
    bindClick(test(), $link['short_url']);

    $perf = test()->actingAs($owner)->getJson('/api/tracking/links/'.$link['id'].'/performance')->json('data');

    expect($perf['spend']['basis'])->toBe('UNAVAILABLE')->and($perf['spend']['reason'])->toContain('not bound')
        ->and($perf['costs']['cpc']['minor'])->toBeNull()
        ->and($perf['revenue']['lead_value']['roas']['value'])->toBeNull();
});

it('refuses spend in two currencies instead of adding them', function (): void {
    $b = bindWorld();
    $owner = $b['w']->scenario->owner;
    $link = test()->actingAs($owner)->postJson('/api/tracking/links', bindBody($b['project'], 'Mixed', ['campaign_id' => $b['campaign']]))->json('data');
    bindClick(test(), $link['short_url']);
    $row = (string) DB::table('daily_metrics')->where('entity_type', 'campaign')->where('entity_id', $b['campaign'])->value('id');
    DB::table('daily_metrics')->where('id', $row)->update(['currency' => 'AED']);

    $spend = test()->actingAs($owner)->getJson('/api/tracking/links/'.$link['id'].'/performance')->json('data.spend');

    expect($spend['basis'])->toBe('UNAVAILABLE')->and($spend['reason'])->toContain('more than one currency');
});

it('prices a lead from the spend and the lead count, and returns on spend only from a matching revenue', function (): void {
    $b = bindWorld();
    $owner = $b['w']->scenario->owner;
    $link = test()->actingAs($owner)->postJson('/api/tracking/links', bindBody($b['project'], 'Earner', ['campaign_id' => $b['campaign']]))->json('data');

    $form = test()->actingAs($owner)->postJson('/api/tracking/forms', [
        'project_id' => $b['project'], 'name' => 'Cost form', 'creates_lead' => true, 'fields' => [
            ['key' => 'name', 'type' => 'text', 'label_en' => 'Name', 'required' => true, 'maps_to' => 'name'],
            ['key' => 'phone', 'type' => 'phone', 'label_en' => 'Phone', 'required' => true, 'maps_to' => 'phone'],
        ],
    ])->assertCreated()->json('data');
    test()->actingAs($owner)->postJson('/api/tracking/forms/'.$form['id'].'/status', ['status' => 'published'])->assertOk();

    foreach ([1, 2] as $n) {
        $location = (string) test()->withHeaders(['User-Agent' => BIND_UA])->get('/api/r/'.basename($link['short_url']))->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $r = test()->postJson('/api/public/forms/'.$form['public_url_token'].'/submit', [
            'event_id' => 'sub-bind-event-'.$n, 'touchpoint' => $q['ds_tp'], 'consent' => 'granted', 'values' => ['name' => 'Person '.$n, 'phone' => '055500300'.$n],
        ]);
        $r->assertCreated();
    }

    $leadId = (string) DB::table('leads')->orderBy('id')->value('id');
    DB::table('leads')->where('id', $leadId)->update(['stage' => 'won', 'value_minor' => 90_000, 'currency' => 'SAR', 'closed_at' => now()]);
    $spendCurrency = (string) DB::table('daily_metrics')->where('entity_type', 'campaign')->where('entity_id', $b['campaign'])->value('currency');

    $perf = test()->actingAs($owner)->getJson('/api/tracking/links/'.$link['id'].'/performance')->assertOk()->json('data');
    $spend = bindCampaignSpend($b['campaign']);

    expect($perf['costs']['cpl']['minor'])->toBe(intdiv($spend + 1, 2))
        ->and($perf['costs']['cac']['minor'])->toBe($spend)
        ->and($perf['revenue']['lead_value']['minor'])->toBe(90_000)
        ->and($perf['revenue']['store_attributed']['minor'])->toBeNull();

    if ($spendCurrency === 'SAR') {
        expect($perf['revenue']['lead_value']['roas']['value'])->toBe(round(90_000 / $spend, 2));
    } else {
        expect($perf['revenue']['lead_value']['roas']['value'])->toBeNull()->and($perf['revenue']['lead_value']['roas']['reason'])->toContain('currencies');
    }
});

it('keeps another workspace out of a link, its performance and the campaign picker', function (): void {
    $b = bindWorld();
    $owner = $b['w']->scenario->owner;
    $link = test()->actingAs($owner)->postJson('/api/tracking/links', bindBody($b['project'], 'Mine', ['campaign_id' => $b['campaign']]))->json('data');
    $other = bindWorld();
    $stranger = $other['w']->scenario->owner;

    test()->actingAs($stranger)->getJson('/api/tracking/links/'.$link['id'].'/performance')->assertNotFound();
    test()->actingAs($stranger)->getJson('/api/tracking/binding-options?project_id='.$b['project'].'&level=accounts')->assertNotFound();
    test()->actingAs($stranger)->postJson('/api/tracking/links', bindBody($other['project'], 'Steal', ['campaign_id' => $b['campaign']]))->assertUnprocessable();
});
