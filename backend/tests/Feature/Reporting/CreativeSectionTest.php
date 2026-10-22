<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

/*
|--------------------------------------------------------------------------
| Creatives in a report
|--------------------------------------------------------------------------
|
| A creative section reads the same layer as the Creatives page, so what is asserted is the report's own promises: every
| view renders, a shared link carries no internal or provider identifier, a visitor's platform filter narrows it, the
| picture reference is honest, and a missing or unsupported thing is said rather than drawn.
|
*/

const CREATIVE_VIEWS = ['top', 'performance', 'format_mix', 'comparison', 'refresh', 'gallery', 'platform_mix'];

/** @return array{workspace: SyncedWorkspace, report: string} */
function creativeReport(string $audience = 'client_safe'): array
{
    $workspace = SyncedWorkspace::default();

    $report = (string) test()->actingAs($workspace->scenario->owner)
        ->postJson('/api/reports', [
            'name' => 'Creatives month',
            'audience' => $audience,
            'window' => 'last_7_days',
            'sections' => array_map(static fn (string $view): array => ['kind' => 'creative', 'creative_view' => $view], CREATIVE_VIEWS),
        ])
        ->assertCreated()
        ->json('data.id');

    return ['workspace' => $workspace, 'report' => $report];
}

/** @param array{workspace: SyncedWorkspace, report: string} $setup */
function creativeLink(array $setup): string
{
    return (string) test()->actingAs($setup['workspace']->scenario->owner)
        ->postJson("/api/reports/{$setup['report']}/shares", ['days' => 7])
        ->assertCreated()
        ->json('meta.token');
}

/** @return array<string, mixed> */
function creativeShared(string $token, string $query = ''): array
{
    /** @var array<string, mixed> $data */
    $data = test()->getJson("/api/shared-reports/{$token}".($query === '' ? '' : "?{$query}"))->assertOk()->json('data');

    return $data;
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function creativeSection(array $data, string $view): array
{
    foreach ($data['report']['sections'] ?? $data['sections'] as $section) {
        if (($section['creative']['view'] ?? null) === $view) {
            return $section;
        }
    }

    throw new LogicException("No {$view} section.");
}

describe('the definition', function (): void {
    it('saves every view and refuses a creative section that names none', function (): void {
        $setup = creativeReport();

        $saved = test()->actingAs($setup['workspace']->scenario->owner)->getJson("/api/reports/{$setup['report']}")->assertOk()->json('data');

        expect($saved['sections'])->toHaveCount(7);

        test()->actingAs($setup['workspace']->scenario->owner)
            ->postJson('/api/reports', ['name' => 'Bad', 'window' => 'last_7_days', 'sections' => [['kind' => 'creative']]])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'creative_view_required');

        test()->actingAs($setup['workspace']->scenario->owner)
            ->postJson('/api/reports', ['name' => 'Bad', 'window' => 'last_7_days', 'sections' => [['kind' => 'creative', 'creative_view' => 'everything']]])
            ->assertUnprocessable();
    });
});

describe('rendering', function (): void {
    it('renders the views from the creative layer, with the plain verdict and the evidence behind it', function (): void {
        $setup = creativeReport();
        $data = test()->actingAs($setup['workspace']->scenario->owner)->getJson("/api/reports/{$setup['report']}")->assertOk()->json('data');

        $performance = creativeSection($data, 'performance');

        expect($performance['is_empty'])->toBeFalse()
            ->and($performance['creative']['rows'])->not->toBe([])
            ->and($performance['creative']['rows'][0])->toHaveKeys(['label', 'platform_label', 'format_label', 'media', 'metrics', 'trend', 'verdict', 'evidence'])
            ->and(creativeSection($data, 'comparison')['creative']['rows'])->toHaveCount(min(4, count($performance['creative']['rows'])));
    });

    it('shows the format mix by spend when the creatives share a currency, and says what a verdict was judged on', function (): void {
        $setup = creativeReport();
        $data = test()->actingAs($setup['workspace']->scenario->owner)->getJson("/api/reports/{$setup['report']}")->assertOk()->json('data');

        $mix = creativeSection($data, 'format_mix')['creative'];

        expect($mix['mix'])->not->toBe([])
            ->and(array_sum(array_column($mix['mix'], 'value')))->toBeGreaterThan(0)
            ->and(implode(' ', creativeSection($data, 'top')['caveats']))->toContain('1,000 times');
    });

    it('lists only wearing-out and weak creatives under refresh', function (): void {
        $setup = creativeReport();
        $data = test()->actingAs($setup['workspace']->scenario->owner)->getJson("/api/reports/{$setup['report']}")->assertOk()->json('data');

        foreach (creativeSection($data, 'refresh')['creative']['rows'] as $row) {
            expect($row['verdict'])->toBeIn(['fatiguing', 'weak']);
        }
    });

    it('never draws a fixture address as a preview', function (): void {
        $setup = creativeReport();
        $body = (string) test()->actingAs($setup['workspace']->scenario->owner)->getJson("/api/reports/{$setup['report']}")->assertOk()->getContent();

        expect($body)->not->toContain('.invalid');
    });

    it('exports the creatives as a table, in CSV and in a workbook', function (): void {
        $setup = creativeReport();

        $csv = test()->actingAs($setup['workspace']->scenario->owner)->get("/api/reports/{$setup['report']}/csv")->assertOk()->getContent();
        test()->actingAs($setup['workspace']->scenario->owner)->get("/api/reports/{$setup['report']}/xlsx")->assertOk();

        expect($csv)->toContain('Top creatives')->and($csv)->toContain('Spend');
    });
});

describe('the gallery and the platform mix', function (): void {
    it('carries the scope\'s own totals and days with the gallery, read from the analytics layer', function (): void {
        $setup = creativeReport();
        $data = test()->actingAs($setup['workspace']->scenario->owner)->getJson("/api/reports/{$setup['report']}")->assertOk()->json('data');
        $gallery = creativeSection($data, 'gallery');

        $page = test()->actingAs($setup['workspace']->scenario->owner)->getJson('/api/analytics?preset=7d&metrics[]=spend')->assertOk()->json('data.summary.values.spend.value');

        expect($gallery['totals']['values']['spend']['value'])->toBe($page)
            ->and($gallery['totals']['values'])->toHaveKeys(['ctr', 'cpa', 'conversions'])
            ->and(count($gallery['series']))->toBe(7)
            ->and($gallery['series'][0]['metrics']['values'])->toHaveKeys(['spend', 'ctr', 'frequency'])
            ->and(count($gallery['creative']['rows']))->toBeLessThanOrEqual(12);
    });

    it('sums each platform\'s creatives over the rows it shows, and withholds money across two currencies', function (): void {
        $setup = creativeReport();
        $data = test()->actingAs($setup['workspace']->scenario->owner)->getJson("/api/reports/{$setup['report']}")->assertOk()->json('data');
        $mix = creativeSection($data, 'platform_mix')['creative']['platform_mix'];

        expect(array_column($mix, 'key'))->toEqualCanonicalizing(['meta', 'google_ads'])
            ->and(array_sum(array_column($mix, 'creatives')))->toBe(count(creativeSection($data, 'platform_mix')['creative']['rows']))
            ->and($mix[0])->toHaveKeys(['label', 'creatives', 'spend', 'results', 'ctr', 'cpa', 'currency']);
    });
});

describe('a shared link', function (): void {
    it('carries the creatives and no internal or provider identifier', function (): void {
        $setup = creativeReport();
        $body = (string) json_encode(creativeShared(creativeLink($setup)));

        $known = [];

        foreach (['campaigns', 'ad_groups', 'ads', 'creatives', 'external_accounts', 'clients', 'projects', 'workspaces', 'reports'] as $table) {
            foreach (DB::table($table)->pluck('id') as $id) {
                $known[] = (string) $id;
            }
        }

        foreach (['ads', 'creatives', 'external_accounts', 'campaigns'] as $table) {
            foreach (DB::table($table)->pluck('external_id') as $external) {
                $known[] = (string) $external;
            }
        }

        expect($known)->not->toBeEmpty()
            ->and($body)->toContain('"kind":"creative"')
            ->and(preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $body))->toBe(0);

        foreach (array_unique($known) as $value) {
            expect($body)->not->toContain($value);
        }
    });

    it('narrows to a platform the report holds, and a narrowed view never holds a creative of another platform', function (): void {
        $setup = creativeReport();
        $token = creativeLink($setup);

        $all = creativeSection(creativeShared($token), 'performance')['creative']['rows'];
        $meta = creativeSection(creativeShared($token, 'platform=meta&refine=1'), 'performance')['creative']['rows'];
        $google = creativeSection(creativeShared($token, 'platform=google_ads&refine=1'), 'performance')['creative']['rows'];

        expect(array_unique(array_column($meta, 'platform')))->toBe(['meta'])
            ->and(array_unique(array_column($google, 'platform')))->toBe(['google_ads'])
            ->and(count($meta) + count($google))->toBe(count($all));
    });
});

describe('a frozen report', function (): void {
    it('keeps the creatives it was frozen with, with the same safety', function (): void {
        $setup = creativeReport();
        $owner = $setup['workspace']->scenario->owner;

        $snapshot = (string) test()->actingAs($owner)
            ->postJson("/api/reports/{$setup['report']}/snapshots", ['label' => 'Frozen'])
            ->assertCreated()
            ->json('data.id');
        $token = (string) test()->actingAs($owner)
            ->postJson("/api/reports/{$setup['report']}/shares", ['days' => 7, 'snapshot_id' => $snapshot])
            ->assertCreated()
            ->json('meta.token');

        $body = (string) json_encode(creativeShared($token));

        expect(creativeSection(creativeShared($token), 'performance')['creative']['rows'])->not->toBe([])
            ->and(preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $body))->toBe(0);
    });
});

describe('narrowing by creative and format', function (): void {
    it('offers the report\'s creatives and formats by opaque key, and narrows to one creative', function (): void {
        $setup = creativeReport();
        $token = creativeLink($setup);
        $all = creativeShared($token);
        $options = $all['filters']['available'];

        expect($options['creatives'])->not->toBe([])
            ->and($options['formats'])->not->toBe([])
            ->and(preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', (string) json_encode($options)))->toBe(0);

        $one = $options['creatives'][0];
        $narrowed = creativeShared($token, "creative={$one['key']}&refine=1");
        $rows = creativeSection($narrowed, 'performance')['creative']['rows'];

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['label'])->toBe($one['label'])
            ->and($narrowed['filters']['applied']['creatives'])->toBe([$one['key']]);
    });

    it('narrows to a format the report holds, and refuses a creative or a format it does not', function (): void {
        $setup = creativeReport();
        $token = creativeLink($setup);
        $format = creativeShared($token)['filters']['available']['formats'][0]['key'];

        $narrowed = creativeShared($token, "format={$format}&refine=1");

        foreach (creativeSection($narrowed, 'performance')['creative']['rows'] as $row) {
            expect($row['format'])->toBe($format);
        }

        test()->getJson("/api/shared-reports/{$token}?creative=not-a-key")->assertUnprocessable();
        test()->getJson("/api/shared-reports/{$token}?format=hologram")->assertUnprocessable();
    });

    it('offers no creative filter on a report that shows no creatives', function (): void {
        $workspace = SyncedWorkspace::default();
        $report = (string) test()->actingAs($workspace->scenario->owner)->postJson('/api/reports', [
            'name' => 'Plain', 'audience' => 'client_safe', 'window' => 'last_7_days',
            'sections' => [['kind' => 'scorecard', 'metrics' => ['spend']]],
        ])->assertCreated()->json('data.id');
        $token = (string) test()->actingAs($workspace->scenario->owner)->postJson("/api/reports/{$report}/shares", ['days' => 7])->assertCreated()->json('meta.token');

        expect(creativeShared($token)['filters']['available']['creatives'])->toBe([]);
        test()->getJson("/api/shared-reports/{$token}?creative=abc")->assertUnprocessable();
    });
});
