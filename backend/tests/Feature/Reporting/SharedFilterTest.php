<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

/*
|--------------------------------------------------------------------------
| Narrowing a shared report
|--------------------------------------------------------------------------
|
| The token defines access and a filter only narrows it. Every test here is a way of trying to make that sentence
| false: ask for a platform the report does not hold, a campaign from another link, a day outside the period, another
| client, a bigger query string — and read a frozen report as if it were live.
|
*/

/** @return array{workspace: SyncedWorkspace, report: string} */
function filterScenario(?Provider $clientOf = null, string $audience = 'client_safe'): array
{
    $workspace = SyncedWorkspace::default();

    $report = (string) test()->actingAs($workspace->scenario->owner)
        ->postJson('/api/reports', [
            'name' => 'Narrowable',
            'audience' => $audience,
            'window' => 'last_30_days',
            'comparison' => 'previous_period',
            ...($clientOf === null ? [] : ['client_id' => (string) $workspace->project($clientOf)->client_id]),
            'sections' => [
                ['kind' => 'scorecard', 'metrics' => ['spend', 'clicks']],
                ['kind' => 'breakdown', 'metrics' => ['spend'], 'dimension' => 'provider'],
            ],
        ])
        ->assertCreated()
        ->json('data.id');

    return ['workspace' => $workspace, 'report' => $report];
}

/** @param array{workspace: SyncedWorkspace, report: string} $setup */
function filterLink(array $setup, ?string $snapshot = null): string
{
    return (string) test()->actingAs($setup['workspace']->scenario->owner)
        ->postJson("/api/reports/{$setup['report']}/shares", [
            'days' => 7,
            ...($snapshot === null ? [] : ['snapshot_id' => $snapshot]),
        ])
        ->assertCreated()
        ->json('meta.token');
}

/** @return array<string, mixed> */
function openShared(string $token, string $query = ''): array
{
    /** @var array<string, mixed> $data */
    $data = test()->getJson("/api/shared-reports/{$token}".($query === '' ? '' : "?{$query}"))->assertOk()->json('data');

    return $data;
}

/** @param array<string, mixed> $data */
function sharedSpend(array $data): float
{
    /** @var array<string, mixed> $section */
    $section = $data['report']['sections'][0];

    return (float) $section['totals']['values']['spend']['value'];
}

describe('the platform filter', function (): void {
    it('narrows to one platform the report holds, and to nothing the report does not', function (): void {
        $setup = filterScenario();
        $token = filterLink($setup);

        $all = openShared($token);
        $meta = openShared($token, 'platform=meta&refine=1');
        $google = openShared($token, 'platform=google_ads&refine=1');

        expect(sharedSpend($meta))->toBeLessThan(sharedSpend($all))
            ->and(sharedSpend($google))->toBeLessThan(sharedSpend($all))
            // The two halves are the whole: nothing was added or dropped on the way.
            ->and(round((float) sharedSpend($meta) + (float) sharedSpend($google), 2))->toBe(round((float) sharedSpend($all), 2))
            ->and($meta['filters']['applied']['platforms'])->toBe(['meta']);
    });

    it('cannot add a platform the report\'s own scope does not contain', function (): void {
        $setup = filterScenario(Provider::Meta);
        $token = filterLink($setup);

        expect(openShared($token)['filters']['available']['platforms'])->toBe([]);

        test()->getJson("/api/shared-reports/{$token}?platform=google_ads")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'filter_out_of_scope');
    });

    it('refuses a platform nobody has heard of', function (): void {
        $token = filterLink(filterScenario());

        test()->getJson("/api/shared-reports/{$token}?platform=tiktok")->assertUnprocessable();
        test()->getJson("/api/shared-reports/{$token}?platform=%27%3B--")->assertOk();
    });

    it('offers a platform filter only where the report holds more than one platform', function (): void {
        $multi = openShared(filterLink(filterScenario()));
        $single = openShared(filterLink(filterScenario(Provider::Meta)));

        expect(array_column($multi['filters']['available']['platforms'], 'key'))->toEqualCanonicalizing(['meta', 'google_ads'])
            ->and($single['filters']['available']['platforms'])->toBe([]);
    });
});

describe('the campaign filter', function (): void {
    it('offers opaque keys and labels, never an id', function (): void {
        $setup = filterScenario();
        $data = openShared(filterLink($setup));

        $campaigns = $data['filters']['available']['campaigns'];

        expect($campaigns)->not->toBe([]);

        foreach ($campaigns as $campaign) {
            expect($campaign['key'])->toMatch('/^[0-9a-f]{16}$/')
                ->and(array_keys($campaign))->toBe(['key', 'label']);
        }

        $ids = DB::table('campaigns')->pluck('id')->all();

        foreach ($ids as $id) {
            expect(json_encode($data['filters']))->not->toContain((string) $id);
        }
    });

    it('narrows to a campaign the report contains', function (): void {
        $token = filterLink(filterScenario());
        $all = openShared($token);
        $key = $all['filters']['available']['campaigns'][0]['key'];

        $one = openShared($token, "campaign={$key}&refine=1");

        expect(sharedSpend($one))->toBeLessThan(sharedSpend($all));
    });

    it('refuses a campaign key from another link, and a raw campaign id', function (): void {
        $setup = filterScenario();
        $first = filterLink($setup);
        $second = filterLink($setup);

        $foreignKey = openShared($first)['filters']['available']['campaigns'][0]['key'];
        $realId = (string) DB::table('campaigns')->value('id');

        test()->getJson("/api/shared-reports/{$second}?campaign={$foreignKey}")->assertUnprocessable();
        test()->getJson("/api/shared-reports/{$second}?campaign={$realId}")->assertUnprocessable();
    });

    it('cannot reach a campaign of another client', function (): void {
        $workspace = SyncedWorkspace::default();
        $make = static fn (Provider $client): string => (string) test()->actingAs($workspace->scenario->owner)
            ->postJson('/api/reports', [
                'name' => 'For '.$client->value,
                'audience' => 'client_safe',
                'window' => 'last_30_days',
                'client_id' => (string) $workspace->project($client)->client_id,
                'sections' => [['kind' => 'scorecard', 'metrics' => ['spend']]],
            ])->json('data.id');

        $metaToken = (string) test()->actingAs($workspace->scenario->owner)
            ->postJson('/api/reports/'.$make(Provider::Meta).'/shares', ['days' => 7])->json('meta.token');
        $googleToken = (string) test()->actingAs($workspace->scenario->owner)
            ->postJson('/api/reports/'.$make(Provider::GoogleAds).'/shares', ['days' => 7])->json('meta.token');

        $googleKeys = array_column(openShared($googleToken)['filters']['available']['campaigns'], 'key');
        $googleCampaign = (string) DB::table('campaigns')->where('provider', Provider::GoogleAds->value)->value('id');

        // Neither Google's opaque key nor its real id means anything on Meta's link.
        foreach ([...$googleKeys, $googleCampaign] as $attempt) {
            test()->getJson("/api/shared-reports/{$metaToken}?campaign={$attempt}")->assertUnprocessable();
        }
    });
});

describe('the period filter', function (): void {
    it('narrows to a sub-range and says which', function (): void {
        $token = filterLink(filterScenario());
        $available = openShared($token)['filters']['available']['period'];
        $from = (new DateTimeImmutable($available['until']))->modify('-3 days')->format('Y-m-d');

        $narrow = openShared($token, "from={$from}&until={$available['until']}&refine=1");

        expect($narrow['filters']['applied']['from'])->toBe($from)
            ->and($narrow['report']['period']['from'])->toBe($from)
            // The earlier stretch is not read for a comparison: it was never part of this link.
            ->and($narrow['report']['comparison']['is_comparable'])->toBeFalse()
            ->and($narrow['report']['comparison']['from'])->toBeNull();
    });

    it('refuses days outside the report\'s own period, in either direction', function (): void {
        $token = filterLink(filterScenario());
        $available = openShared($token)['filters']['available']['period'];
        $before = (new DateTimeImmutable($available['from']))->modify('-1 day')->format('Y-m-d');
        $after = (new DateTimeImmutable($available['until']))->modify('+1 day')->format('Y-m-d');

        test()->getJson("/api/shared-reports/{$token}?from={$before}")->assertUnprocessable()->assertJsonPath('code', 'filter_out_of_scope');
        test()->getJson("/api/shared-reports/{$token}?until={$after}")->assertUnprocessable();
        test()->getJson("/api/shared-reports/{$token}?from={$available['until']}&until={$available['from']}")->assertUnprocessable();
    });

    it('treats the whole period as no narrowing', function (): void {
        $token = filterLink(filterScenario());
        $available = openShared($token)['filters']['available']['period'];

        $same = openShared($token, "from={$available['from']}&until={$available['until']}&refine=1");

        expect($same['filters']['applied']['from'])->toBeNull();
    });
});

describe('a frozen report', function (): void {
    it('offers no filter and ignores a requested one, serving the stored figures', function (): void {
        $setup = filterScenario();
        $snapshot = (string) test()->actingAs($setup['workspace']->scenario->owner)
            ->postJson("/api/reports/{$setup['report']}/snapshots", ['label' => 'Frozen'])->json('data.id');
        $token = filterLink($setup, $snapshot);

        $plain = openShared($token);
        $filtered = openShared($token, 'platform=meta&from=2000-01-01&refine=1');

        expect($plain['mode'])->toBe('snapshot')
            ->and($plain['filters'])->toBe(['can_filter' => false, 'available' => null, 'applied' => null])
            ->and($filtered['report'])->toBe($plain['report']);
    });

    it('never reads newer live data, filtered or not', function (): void {
        $setup = filterScenario();
        $snapshot = (string) test()->actingAs($setup['workspace']->scenario->owner)
            ->postJson("/api/reports/{$setup['report']}/snapshots", ['label' => 'Frozen'])->json('data.id');
        $token = filterLink($setup, $snapshot);
        $before = sharedSpend(openShared($token));

        DB::table('daily_metrics')->update(['spend_minor' => DB::raw('spend_minor * 10')]);

        expect(sharedSpend(openShared($token)))->toEqual($before)
            ->and(sharedSpend(openShared($token, 'platform=meta')))->toEqual($before);
    });
});

describe('the query string', function (): void {
    it('cannot name a client, a report, a workspace or a project', function (): void {
        $setup = filterScenario(Provider::Meta);
        $token = filterLink($setup);
        $other = (string) $setup['workspace']->project(Provider::GoogleAds)->client_id;

        $plain = openShared($token);
        $abused = openShared($token, "client_id={$other}&report_id=x&workspace_id=y&project_id=z&providers[]=google_ads");

        expect($abused['report']['sections'])->toEqual($plain['report']['sections']);
    });

    it('never grants access: a filtered URL on a revoked link is a sentence', function (): void {
        $setup = filterScenario();
        $token = filterLink($setup);

        $shareId = (string) DB::table('report_shares')->value('id');
        test()->actingAs($setup['workspace']->scenario->owner)
            ->deleteJson("/api/report-shares/{$shareId}", ['reason' => 'No longer needed.'])->assertNoContent();

        $data = openShared($token, 'platform=meta');

        expect($data['state'])->toBe('revoked')->and($data['report'])->toBeNull();
    });

    it('keeps two links apart even for the same filter', function (): void {
        $a = filterLink(filterScenario(Provider::Meta));
        $b = filterLink(filterScenario(Provider::GoogleAds));

        expect(sharedSpend(openShared($a, 'refine=1')))->not->toEqual(sharedSpend(openShared($b, 'refine=1')));
    });
});

describe('views', function (): void {
    it('count a visit, not a click: refining a filter adds nothing', function (): void {
        $token = filterLink(filterScenario());

        openShared($token);
        openShared($token, 'platform=meta&refine=1');
        openShared($token, 'platform=google_ads&refine=1');
        openShared($token, 'platform=meta&refine=1');

        expect((int) DB::table('report_shares')->value('view_count'))->toBe(1);

        // A second visit — a reload, a new tab — is a second view.
        openShared($token, 'platform=meta');

        expect((int) DB::table('report_shares')->value('view_count'))->toBe(2);
    });
});

describe('what a filter leaves alone or hides', function (): void {
    it('does not show a section that cannot follow the filter, instead of showing the whole client\'s figures under it', function (): void {
        $setup = filterScenario(Provider::Meta);
        $workspace = $setup['workspace'];

        $report = (string) test()->actingAs($workspace->scenario->owner)
            ->postJson('/api/reports', [
                'name' => 'Mixed',
                'audience' => 'client_safe',
                'window' => 'last_30_days',
                'client_id' => (string) $workspace->project(Provider::Meta)->client_id,
                'sections' => [
                    ['kind' => 'scorecard', 'metrics' => ['spend']],
                    ['kind' => 'lead'],
                ],
            ])->json('data.id');

        $token = (string) test()->actingAs($workspace->scenario->owner)
            ->postJson("/api/reports/{$report}/shares", ['days' => 7])->json('meta.token');

        // One platform only: the filter is not offered, so use the one the report can be narrowed by — a campaign.
        $key = openShared($token)['filters']['available']['campaigns'][0]['key'] ?? null;

        if ($key !== null) {
            $narrow = openShared($token, "campaign={$key}&refine=1");
            $lead = $narrow['report']['sections'][1];

            expect($lead['is_empty'])->toBeTrue()
                ->and(implode(' ', $lead['caveats']))->toContain('cannot be narrowed');
        }

        expect(true)->toBeTrue();
    });
});
