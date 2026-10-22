<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

/*
|--------------------------------------------------------------------------
| How creatives performed
|--------------------------------------------------------------------------
|
| Over the real sandbox sync, adjusted only where a test needs a particular shape. What is asserted is mostly what must not
| happen: another workspace's creative appearing, an ad with no creative being counted under an asset, a fixture address
| being drawn as a preview, a missing figure becoming zero, a verdict called on one number.
|
*/

/**
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function creativeRead(SyncedWorkspace $workspace, array $query = [], ?object $as = null): array
{
    /** @var array<string, mixed> $data */
    $data = test()->actingAs($as ?? $workspace->scenario->owner)
        ->getJson('/api/analytics/creatives?'.http_build_query(['preset' => '7d', ...$query]))
        ->assertOk()
        ->json('data');

    return $data;
}

/**
 * @param  array<string, mixed>  $data
 * @return list<string>
 */
function creativeKeys(array $data): array
{
    return array_column($data['rows'], 'key');
}

/**
 * @param  array<int, array<string, mixed>>  $rows
 * @return array<string, mixed>
 */
function creativeFind(array $rows, string $key): array
{
    foreach ($rows as $row) {
        if ($row['key'] === $key) {
            return $row;
        }
    }

    throw new LogicException("No creative row {$key} in the answer.");
}

describe('aggregation', function (): void {
    it('reads one row per asset, summing the ads that run it', function (): void {
        $workspace = SyncedWorkspace::default();
        [$first, $second] = DB::table('ads')->where('provider', 'meta')->orderBy('id')->limit(2)->pluck('id')->all();
        $creative = (string) DB::table('ads')->where('id', $first)->value('creative_id');
        DB::table('ads')->where('id', $second)->update(['creative_id' => $creative]);

        $range = ['preset' => '7d'];
        $asset = creativeFind(creativeRead($workspace, [...$range, 'providers' => ['meta']])['rows'], $creative);

        $expected = (int) DB::table('daily_metrics')
            ->where('entity_type', 'ad')
            ->whereIn('ad_id', [$first, $second])
            ->whereBetween('date', [(new DateTimeImmutable('-7 days'))->format('Y-m-d'), (new DateTimeImmutable('-1 day'))->format('Y-m-d')])
            ->sum('spend_minor');

        expect($asset)->not->toBeNull()
            ->and($asset['ads'])->toBe(2)
            ->and($asset['metrics']['values']['spend']['value'])->toBe($expected);
    });

    it('reads the same money as the ad rows when asked by ad — asset and ad are different questions, not different totals', function (): void {
        $workspace = SyncedWorkspace::default();

        $byAsset = creativeRead($workspace, ['providers' => ['meta'], 'limit' => 100]);
        $byAd = creativeRead($workspace, ['providers' => ['meta'], 'grain' => 'ad', 'limit' => 100]);

        $sum = static fn (array $data): int => (int) array_sum(array_map(
            static fn (array $row): int => (int) ($row['metrics']['values']['spend']['value'] ?? 0),
            $data['rows'],
        ));

        // Every Meta ad in the sandbox has a creative, so nothing falls out of either grain.
        expect($sum($byAsset))->toBe($sum($byAd))
            ->and(count($byAd['rows']))->toBeGreaterThanOrEqual(count($byAsset['rows']))
            ->and($byAd['rows'][0]['ad_name'])->not->toBeNull()
            ->and($byAsset['rows'][0]['ad_name'])->toBeNull();
    });

    it('leaves an ad with no known creative out of every asset, and keeps it as an ad', function (): void {
        $workspace = SyncedWorkspace::default();
        $ad = (string) DB::table('ads')->where('provider', 'meta')->orderBy('id')->value('id');
        DB::table('ads')->where('id', $ad)->update(['creative_id' => null]);

        $assets = creativeRead($workspace, ['providers' => ['meta'], 'limit' => 100]);
        $ads = creativeRead($workspace, ['providers' => ['meta'], 'grain' => 'ad', 'limit' => 100]);
        $row = creativeFind($ads['rows'], $ad);

        // Not under another asset, not under a made-up "unknown" one.
        expect(creativeKeys($assets))->not->toContain($ad)
            ->and($row)->not->toBeNull()
            ->and($row['asset_key'])->toBeNull()
            ->and($row['media']['state'])->toBe('none');
    });

    it('does not merge two creatives that merely look alike', function (): void {
        $workspace = SyncedWorkspace::default();
        $ids = DB::table('creatives')->where('provider', 'meta')->orderBy('id')->limit(2)->pluck('id')->all();
        DB::table('creatives')->whereIn('id', $ids)->update(['name' => 'Same name', 'title' => 'Same caption']);

        $rows = creativeRead($workspace, ['providers' => ['meta'], 'limit' => 100])['rows'];

        expect(count(array_filter(creativeKeys(['rows' => $rows]), static fn (string $key): bool => in_array($key, $ids, true))))->toBe(2);
    });
});

describe('scope', function (): void {
    it('never reads another workspace\'s creatives', function (): void {
        $mine = SyncedWorkspace::default();
        $mineKeys = creativeKeys(creativeRead($mine, ['limit' => 100]));
        $mineIds = DB::table('creatives')->where('workspace_id', $mine->workspaceId())->pluck('id')->all();

        $other = SyncedWorkspace::default();
        $theirIds = DB::table('creatives')->where('workspace_id', $other->workspaceId())->pluck('id')->all();

        $again = creativeKeys(creativeRead($mine, ['limit' => 100]));

        expect($again)->toEqualCanonicalizing($mineKeys)
            ->and(array_intersect($again, $theirIds))->toBe([])
            ->and(array_diff($again, $mineIds))->toBe([]);
    });

    it('narrows to a client, and to a platform', function (): void {
        $workspace = SyncedWorkspace::default();
        $client = (string) $workspace->project(Provider::Meta)->client_id;

        $forClient = creativeRead($workspace, ['client_id' => $client, 'limit' => 100]);
        $forGoogle = creativeRead($workspace, ['providers' => ['google_ads'], 'limit' => 100]);

        expect(array_unique(array_column($forClient['rows'], 'platform')))->toBe(['meta'])
            ->and(array_unique(array_column($forGoogle['rows'], 'platform')))->toBe(['google_ads']);
    });

    it('narrows to a project, and to a campaign', function (): void {
        $workspace = SyncedWorkspace::default();
        $project = (string) $workspace->project(Provider::GoogleAds)->getKey();
        $campaign = (string) DB::table('campaigns')->where('provider', 'meta')->orderBy('id')->value('id');
        $expected = DB::table('ads')->where('campaign_id', $campaign)->whereNotNull('creative_id')->distinct()->pluck('creative_id')->all();

        $forProject = creativeRead($workspace, ['project_ids' => [$project], 'limit' => 100]);
        $forCampaign = creativeRead($workspace, ['campaign_ids' => [$campaign], 'limit' => 100]);

        expect(array_unique(array_column($forProject['rows'], 'platform')))->toBe(['google_ads'])
            ->and(creativeKeys($forCampaign))->toEqualCanonicalizing(array_map('strval', $expected));
    });

    it('shows a member who is on no project nothing, rather than everything', function (): void {
        $workspace = SyncedWorkspace::default();

        // The buyer holds the analytics permission and is assigned to no project — visibility is the assignment.
        $buyer = $workspace->scenario->addMember(App\Domain\Access\SystemRole::MediaBuyer);

        $data = creativeRead($workspace, [], $buyer);

        expect($data['rows'])->toBe([])->and($data['total'])->toBe(0);
    });

    it('refuses a role without the analytics permission, and an unauthenticated caller', function (): void {
        $workspace = SyncedWorkspace::default();

        test()->actingAs($workspace->scenario->addMember(App\Domain\Access\SystemRole::Creative))->getJson('/api/analytics/creatives')->assertForbidden();
        app('auth')->forgetGuards();
        test()->getJson('/api/analytics/creatives')->assertUnauthorized();
    });
});

describe('media', function (): void {
    it('draws no fixture address, and says why there is no preview', function (): void {
        $workspace = SyncedWorkspace::default();
        $rows = creativeRead($workspace, ['providers' => ['meta'], 'limit' => 100])['rows'];

        $withFixture = array_values(array_filter($rows, static fn (array $row): bool => $row['media']['state'] === 'fixture'));
        $body = (string) json_encode($rows);

        expect($withFixture)->not->toBe([])
            ->and($body)->not->toContain('.invalid')
            ->and(array_unique(array_column(array_column($withFixture, 'media'), 'url')))->toBe([null]);
    });

    it('shows a real thumbnail, and a first image asset where the creative has none of its own', function (): void {
        $workspace = SyncedWorkspace::default();
        [$a, $b] = DB::table('creatives')->where('provider', 'meta')->orderBy('id')->limit(2)->pluck('id')->all();
        DB::table('creatives')->where('id', $a)->update(['thumbnail_url' => 'https://scontent.xx.fbcdn.net/real-a.jpg', 'format' => 'image']);
        DB::table('creatives')->where('id', $b)->update(['thumbnail_url' => null, 'format' => 'image']);
        DB::table('creative_assets')->insert([
            'id' => (string) Illuminate\Support\Str::uuid7(),
            'creative_id' => $b,
            'kind' => 'image',
            'url' => '/demo-creatives/asset-b.png',
            'position' => 0,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = creativeRead($workspace, ['providers' => ['meta'], 'limit' => 100])['rows'];

        expect(creativeFind($rows, $a)['media'])->toMatchArray(['state' => 'available', 'url' => 'https://scontent.xx.fbcdn.net/real-a.jpg', 'origin' => 'provider'])
            ->and(creativeFind($rows, $b)['media'])->toMatchArray(['state' => 'available', 'url' => '/demo-creatives/asset-b.png', 'origin' => 'app']);
    });

    it('never passes on an address that carries a credential', function (): void {
        $workspace = SyncedWorkspace::default();
        $id = (string) DB::table('creatives')->where('provider', 'meta')->orderBy('id')->value('id');
        DB::table('creative_assets')->where('creative_id', $id)->delete();
        DB::table('creatives')->where('id', $id)->update(['thumbnail_url' => 'https://cdn.provider.com/a.jpg?signature=abc&expires=1', 'preview_url' => null]);

        $row = creativeFind(creativeRead($workspace, ['providers' => ['meta'], 'limit' => 100])['rows'], $id);

        expect($row['media']['state'])->toBe('none')->and((string) json_encode($row))->not->toContain('signature');
    });
});

describe('figures', function (): void {
    it('reads a figure the platform did not report as missing, with the layer\'s own words, not as zero', function (): void {
        $workspace = SyncedWorkspace::default();
        $rows = creativeRead($workspace, ['providers' => ['google_ads'], 'limit' => 100])['rows'];

        // Google reports no reach, so no frequency can be worked out for any of its creatives.
        foreach ($rows as $row) {
            expect($row['metrics']['values']['frequency']['value'])->toBeNull();
        }
    });

    it('carries a daily trend for each row, in one read', function (): void {
        $workspace = SyncedWorkspace::default();
        $rows = creativeRead($workspace, ['providers' => ['meta'], 'limit' => 5])['rows'];

        expect($rows)->not->toBe([])
            ->and(count($rows[0]['trend']))->toBe(7)
            ->and(array_keys($rows[0]['trend'][0]))->toBe(['date', 'spend', 'ctr']);
    });

    it('exposes no provider identifier, account or token anywhere in a row', function (): void {
        $workspace = SyncedWorkspace::default();
        $body = (string) json_encode(creativeRead($workspace, ['limit' => 100, 'grain' => 'ad']));

        foreach (DB::table('ads')->pluck('external_id') as $external) {
            expect($body)->not->toContain((string) $external);
        }

        foreach (DB::table('creatives')->pluck('external_id') as $external) {
            expect($body)->not->toContain((string) $external);
        }

        foreach (DB::table('external_accounts')->pluck('external_id') as $external) {
            expect($body)->not->toContain((string) $external);
        }

        expect($body)->not->toContain('external_id')->and($body)->not->toContain('token');
    });
});

describe('verdicts', function (): void {
    /** Make one Meta ad's click-through fall and its frequency rise between the two weeks the sandbox covers. */
    function wearOut(string $ad): void
    {
        $today = new DateTimeImmutable('today');
        $current = [$today->modify('-7 days')->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d')];
        $before = [$today->modify('-14 days')->format('Y-m-d'), $today->modify('-8 days')->format('Y-m-d')];

        DB::table('daily_metrics')->where('entity_type', 'ad')->where('ad_id', $ad)->whereBetween('date', $before)
            ->update(['impressions' => 50_000, 'reach' => 25_000, 'clicks' => 1_000]);
        DB::table('daily_metrics')->where('entity_type', 'ad')->where('ad_id', $ad)->whereBetween('date', $current)
            ->update(['impressions' => 50_000, 'reach' => 10_000, 'clicks' => 500]);
    }

    it('calls a creative fatiguing on a falling click-through and a rising frequency, with the evidence', function (): void {
        $workspace = SyncedWorkspace::default();
        $ad = (string) DB::table('ads')->where('provider', 'meta')->orderBy('id')->value('id');
        wearOut($ad);

        $row = creativeFind(creativeRead($workspace, ['providers' => ['meta'], 'grain' => 'ad', 'limit' => 100])['rows'], $ad);

        expect($row['verdict'])->toBe('fatiguing')
            ->and(array_column($row['evidence'], 'signal'))->toContain('ctr_change')->toContain('frequency_change');
    });

    it('does not call fatigue when only the click-through fell', function (): void {
        $workspace = SyncedWorkspace::default();
        $ad = (string) DB::table('ads')->where('provider', 'meta')->orderBy('id')->value('id');
        wearOut($ad);
        // The same people, seen as often: nothing else got worse.
        DB::table('daily_metrics')->where('entity_type', 'ad')->where('ad_id', $ad)->update(['reach' => 25_000, 'conversions' => null]);

        $row = creativeFind(creativeRead($workspace, ['providers' => ['meta'], 'grain' => 'ad', 'limit' => 100])['rows'], $ad);

        expect($row['verdict'])->not->toBe('fatiguing');
    });

    it('can be asked for by verdict', function (): void {
        $workspace = SyncedWorkspace::default();
        $ad = (string) DB::table('ads')->where('provider', 'meta')->orderBy('id')->value('id');
        wearOut($ad);

        $data = creativeRead($workspace, ['providers' => ['meta'], 'grain' => 'ad', 'verdict' => 'fatiguing', 'limit' => 100]);

        expect(creativeKeys($data))->toBe([$ad])->and($data['total'])->toBe(1);
    });
});

describe('paging', function (): void {
    it('pages without repeating or skipping a row, and says whether there is more', function (): void {
        $workspace = SyncedWorkspace::default();

        $all = creativeRead($workspace, ['limit' => 100]);
        $first = creativeRead($workspace, ['limit' => 5]);
        $second = creativeRead($workspace, ['limit' => 5, 'offset' => 5]);

        expect($first['has_more'])->toBeTrue()
            ->and([...creativeKeys($first), ...creativeKeys($second)])->toBe(array_slice(creativeKeys($all), 0, 10))
            ->and($all['has_more'])->toBeFalse();
    });

    it('refuses a period wider than the layer answers, and a page wider than a hundred', function (): void {
        $workspace = SyncedWorkspace::default();
        $as = test()->actingAs($workspace->scenario->owner);

        $as->getJson('/api/analytics/creatives?start=2024-01-01&end=2026-01-01')->assertUnprocessable();
        $as->getJson('/api/analytics/creatives?limit=500')->assertUnprocessable();
    });

    it('counts formats and platforms over the whole selection, so a filter\'s own options do not shrink as it is used', function (): void {
        $workspace = SyncedWorkspace::default();

        $everything = creativeRead($workspace, ['limit' => 100])['facets'];
        $videos = creativeRead($workspace, ['limit' => 100, 'format' => 'video'])['facets'];

        expect($videos)->toBe($everything);
    });
});

describe('ads as a screen of their own', function (): void {
    it('says where each ad sits — its ad group and its campaign — and nothing for an asset', function (): void {
        $workspace = SyncedWorkspace::default();

        $ads = creativeRead($workspace, ['grain' => 'ad', 'providers' => ['meta'], 'limit' => 100])['rows'];

        expect($ads)->not->toBeEmpty();

        foreach ($ads as $row) {
            $parents = $row['parents'];
            $ad = DB::table('ads')->where('id', $row['key'])->first();
            expect($ad)->not->toBeNull();
            assert($ad instanceof stdClass);

            expect($parents['ad_group']['id'])->toBe((string) $ad->ad_group_id)
                ->and($parents['campaign']['id'])->toBe((string) $ad->campaign_id)
                ->and($parents['ad_group']['name'])->toBe((string) DB::table('ad_groups')->where('id', $ad->ad_group_id)->value('name'));
        }

        foreach (creativeRead($workspace, ['grain' => 'asset', 'providers' => ['meta'], 'limit' => 100])['rows'] as $asset) {
            expect($asset['parents'])->toBeNull();
        }
    });

    it('narrows to one ad group’s ads', function (): void {
        $workspace = SyncedWorkspace::default();
        $groupId = (string) DB::table('ads')->where('provider', 'meta')->orderBy('id')->value('ad_group_id');
        $expected = DB::table('ads')->where('ad_group_id', $groupId)->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $rows = creativeRead($workspace, ['grain' => 'ad', 'ad_group_ids' => [$groupId], 'limit' => 100])['rows'];

        expect(creativeKeys(['rows' => $rows]))->not->toBeEmpty()
            ->and(array_diff(creativeKeys(['rows' => $rows]), $expected))->toBe([]);
    });

    it('narrows to the ads’ own status, not their campaign’s', function (): void {
        $workspace = SyncedWorkspace::default();
        $ad = (string) DB::table('ads')->where('provider', 'meta')->orderBy('id')->value('id');
        DB::table('ads')->where('id', $ad)->update(['status' => 'paused']);

        $paused = creativeRead($workspace, ['grain' => 'ad', 'ad_status' => 'paused', 'providers' => ['meta'], 'limit' => 100]);

        expect(creativeKeys($paused))->toContain($ad);

        foreach ($paused['rows'] as $row) {
            expect($row['ad_status'])->toBe('paused');
        }

        $active = creativeRead($workspace, ['grain' => 'ad', 'ad_status' => 'active', 'providers' => ['meta'], 'limit' => 100]);

        expect(creativeKeys($active))->not->toContain($ad);
    });

    it('refuses an ad status it does not know', function (): void {
        $workspace = SyncedWorkspace::default();

        $this->actingAs($workspace->scenario->owner)
            ->getJson('/api/analytics/creatives?grain=ad&ad_status=sideways')
            ->assertStatus(422);
    });
});
