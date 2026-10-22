<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\SyncedWorkspace;

/**
 * The Ad Groups screen: every ad group the reader may see, each on its own metric rows.
 *
 * What matters here is the same thing that matters for rules at this level: an ad group's spend is what the platform
 * reports *for the ad group* — not its campaign's, not its ads' — and a campaign's verdict is shown as the campaign's.
 */
function adGroupWorld(): SyncedWorkspace
{
    return SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
}

/** An ad group's own spend over the screen's default window (28 days ending yesterday), in minor units. */
function adGroupOwnSpend(string $id, int $days = 28): int
{
    $to = Carbon::now()->subDay()->toDateString();
    $from = Carbon::now()->subDay()->subDays($days - 1)->toDateString();

    return (int) DB::table('daily_metrics')->where(['entity_type' => 'ad_group', 'entity_id' => $id])
        ->whereBetween('date', [$from, $to])->sum('spend_minor');
}

it('lists ad groups on their own figures, never their campaign’s or their ads’', function (): void {
    $w = adGroupWorld();

    $rows = $this->actingAs($w->scenario->owner)->getJson('/api/ad-groups?limit=200')
        ->assertOk()->json('data');

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row['spend_minor'])->toBe(adGroupOwnSpend($row['id']) ?: null);
    }

    /* The same money reported again one level down must not move an ad group's figure. */
    $before = array_column($rows, 'spend_minor', 'id');

    foreach (DB::table('daily_metrics')->where('entity_type', 'ad_group')->get() as $row) {
        DB::table('daily_metrics')->insert([
            ...(array) $row,
            'id' => (string) Str::uuid(),
            'entity_type' => 'ad',
            'entity_id' => (string) Str::uuid(),
        ]);
    }

    $after = $this->actingAs($w->scenario->owner)->getJson('/api/ad-groups?limit=200')->json('data');

    expect(array_column($after, 'spend_minor', 'id'))->toEqual($before);
});

it('names the parent campaign and shows its verdict as the campaign’s own', function (): void {
    $w = adGroupWorld();

    $row = $this->actingAs($w->scenario->owner)->getJson('/api/ad-groups?limit=1')->assertOk()->json('data.0');

    expect($row['campaign']['id'])->toBeString()
        ->and($row['campaign']['name'])->toBeString()
        ->and($row['campaign_health']['health'])->toBeString()
        ->and($row['campaign_health'])->toHaveKeys(['label', 'reason', 'reason_code']);

    /* The same campaign, read on its own screen, carries the same verdict. */
    /** @var list<array<string, mixed>> $campaigns */
    $campaigns = $this->actingAs($w->scenario->owner)->getJson('/api/campaigns/control?limit=200')->json('data');
    $campaign = array_column($campaigns, null, 'id')[$row['campaign']['id']];

    expect($row['campaign_health']['health'])->toBe($campaign['health']);
});

it('publishes the click-through rate only where impressions were reported', function (): void {
    $w = adGroupWorld();

    foreach ($this->actingAs($w->scenario->owner)->getJson('/api/ad-groups?limit=200')->json('data') as $row) {
        $expected = $row['impressions'] !== null && $row['impressions'] > 0 && $row['clicks'] !== null
            ? $row['clicks'] / $row['impressions']
            : null;

        expect($row['ctr'])->toBe($expected);
    }
});

it('narrows to one campaign, to a status and to a search, and refuses a value it does not know', function (): void {
    $w = adGroupWorld();
    $all = $this->actingAs($w->scenario->owner)->getJson('/api/ad-groups?limit=200')->json('data');
    $campaignId = $all[0]['campaign']['id'];

    $inCampaign = $this->actingAs($w->scenario->owner)->getJson('/api/ad-groups?limit=200&campaign_id='.$campaignId)
        ->assertOk()->json('data');

    expect($inCampaign)->not->toBeEmpty()
        ->and(array_unique(array_column(array_column($inCampaign, 'campaign'), 'id')))->toBe([$campaignId]);

    $name = $all[0]['name'];
    $found = $this->actingAs($w->scenario->owner)->getJson('/api/ad-groups?search='.urlencode($name))
        ->assertOk()->json('data.*.name');

    expect($found)->toContain($name);

    $this->actingAs($w->scenario->owner)->getJson('/api/ad-groups?search=zzzz-no-such-thing')
        ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.matched', 0);

    $this->actingAs($w->scenario->owner)->getJson('/api/ad-groups?status=sideways')
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    $this->actingAs($w->scenario->owner)->getJson('/api/ad-groups?sort=vibes')
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
});

it('sorts on the server, nulls last, and reverses on request', function (): void {
    $w = adGroupWorld();

    $spend = array_column(
        $this->actingAs($w->scenario->owner)->getJson('/api/ad-groups?sort=spend&limit=200')->json('data'),
        'spend_minor',
    );
    $known = array_values(array_filter($spend, static fn ($v): bool => $v !== null));
    $sorted = $known;
    rsort($sorted);

    expect($known)->toBe($sorted);

    $reversed = array_column(
        $this->actingAs($w->scenario->owner)->getJson('/api/ad-groups?sort=spend&direction=desc&limit=200')->json('data'),
        'spend_minor',
    );

    expect(array_reverse($reversed))->toBe($spend);
});

it('publishes the spend of everything matched, per currency, beside the page', function (): void {
    $w = adGroupWorld();

    $all = $this->actingAs($w->scenario->owner)->getJson('/api/ad-groups?limit=200')->assertOk();
    $total = array_sum(array_filter(array_column($all->json('data'), 'spend_minor'), static fn ($v): bool => $v !== null));
    $page = $this->actingAs($w->scenario->owner)->getJson('/api/ad-groups?limit=1')->assertOk();

    expect($page->json('meta.matched'))->toBe($all->json('meta.matched'))
        ->and(array_sum(array_column($page->json('meta.spend_by_currency'), 'spend_minor')))->toBe((int) $total);
});

it('shows a media buyer nothing from projects they are not on', function (): void {
    $w = adGroupWorld();
    $buyer = $w->scenario->addMember(SystemRole::MediaBuyer);

    $this->actingAs($buyer)->getJson('/api/ad-groups')
        ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.matched', 0);
});

it('refuses a reader who may not read performance', function (): void {
    $w = adGroupWorld();
    $creative = $w->scenario->addMember(SystemRole::Creative);

    $this->actingAs($creative)->getJson('/api/ad-groups')
        ->assertStatus(Response::HTTP_FORBIDDEN);
});
