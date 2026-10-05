<?php

declare(strict_types=1);

use App\Domain\Advertising\MetricSource;
use App\Domain\Budget\PacingStatus;
use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\Budget;
use App\Infrastructure\Persistence\Eloquent\Model\Campaign;
use App\Infrastructure\Persistence\Eloquent\Model\DailyMetric;
use Tests\Support\SyncedWorkspace;

/*
|--------------------------------------------------------------------------
| Budget pacing
|--------------------------------------------------------------------------
|
| Pacing compares a figure a client approved with a figure the platforms reported, so
| both sides have to be honest about themselves. These tests run the real sync over the
| sandbox fixtures and then pace against what it wrote — which means the spend under
| test is spend the pipeline actually produced, including the nulls.
|
*/

/**
 * A workspace with Meta synced, and an approved budget over the synced period.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{workspace: SyncedWorkspace, budgetId: string}
 */
function pacedWorkspace(array $overrides = []): array
{
    $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $project = $workspace->project(Provider::Meta);

    $payload = [
        'client_id' => (string) $project->getAttribute('client_id'),
        'project_id' => (string) $project->getKey(),
        'name' => 'Synced period',
        // The fixtures cover a recent window, so a wide period catches all of it.
        'period_start' => now()->subDays(40)->format('Y-m-d'),
        'period_end' => now()->addDays(10)->format('Y-m-d'),
        'amount' => '50000.00',
        'currency' => 'SAR',
        ...$overrides,
    ];

    $id = test()->actingAs($workspace->scenario->owner)
        ->postJson('/api/budgets', $payload)
        ->assertCreated()
        ->json('data.id');

    test()->actingAs($workspace->scenario->owner)->postJson("/api/budgets/{$id}/approve")->assertOk();

    return ['workspace' => $workspace, 'budgetId' => $id];
}

/**
 * The first allocation row whose field matches.
 *
 * @param  mixed  $rows  the decoded `allocations` list
 * @return array<string, mixed>
 */
function allocationRow(mixed $rows, string $field, string $value): array
{
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (is_array($row) && ($row[$field] ?? null) === $value) {
            /** @var array<string, mixed> $row */
            return $row;
        }
    }

    throw new RuntimeException("No allocation row with {$field} = {$value}.");
}

it('paces an approved budget against the spend the sync wrote', function (): void {
    $setup = pacedWorkspace();

    $pacing = $this->actingAs($setup['workspace']->scenario->owner)
        ->getJson("/api/budgets/{$setup['budgetId']}/pacing")
        ->assertOk()
        ->json('data');

    expect($pacing['spend']['minor_units'])->toBeGreaterThan(0)
        ->and($pacing['pacing'])->not->toBeNull()
        ->and($pacing['pacing']['spent_minor'])->toBe($pacing['spend']['minor_units'])
        ->and($pacing['pacing']['budget_minor'])->toBe(5_000_000)
        // Published so a reader knows what "expected" means without a footnote.
        ->and($pacing['pacing']['tolerance'])->toBe(0.1);
});

it('labels sandbox spend as sandbox all the way to the pacing figure', function (): void {
    $setup = pacedWorkspace();

    $pacing = $this->actingAs($setup['workspace']->scenario->owner)
        ->getJson("/api/budgets/{$setup['budgetId']}/pacing")
        ->json('data');

    /*
     * The honesty label travels with the number. A pacing screen that lost it would
     * present fixture spend as a client's actual spend, which is the one thing this
     * product must never do.
     */
    expect($pacing['spend']['source'])->toBe(MetricSource::Sandbox->value);
});

it('sums the campaign level only, never the levels together', function (): void {
    $setup = pacedWorkspace();

    $pacing = $this->actingAs($setup['workspace']->scenario->owner)
        ->getJson("/api/budgets/{$setup['budgetId']}/pacing")
        ->json('data');

    $campaignLevel = (int) DailyMetric::query()
        ->where('entity_type', 'campaign')
        ->where('currency', 'SAR')
        ->sum('spend_minor');

    $everyLevel = (int) DailyMetric::query()->where('currency', 'SAR')->sum('spend_minor');

    /*
     * `daily_metrics` holds a row per entity per day at three levels. Adding them all
     * would roughly triple the spend — and the resulting figure would look entirely
     * plausible next to a budget.
     */
    expect($pacing['spend']['minor_units'])->toBe($campaignLevel)
        ->and($everyLevel)->toBeGreaterThan($campaignLevel);
});

it('refuses to pace a draft, and says why', function (): void {
    $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $project = $workspace->project(Provider::Meta);

    $id = $this->actingAs($workspace->scenario->owner)
        ->postJson('/api/budgets', [
            'client_id' => (string) $project->getAttribute('client_id'),
            'project_id' => (string) $project->getKey(),
            'name' => 'Still a proposal',
            'period_start' => now()->subDays(10)->format('Y-m-d'),
            'period_end' => now()->addDays(10)->format('Y-m-d'),
            'amount' => '50000.00',
            'currency' => 'SAR',
        ])
        ->json('data.id');

    $pacing = $this->actingAs($workspace->scenario->owner)
        ->getJson("/api/budgets/{$id}/pacing")
        ->assertOk()
        ->json('data');

    /*
     * A variance from a figure nobody agreed to means nothing. The reason is reported
     * rather than a status, so the screen can say so instead of showing a green tick.
     */
    expect($pacing['pacing'])->toBeNull()
        ->and($pacing['unavailable_reason'])->toContain('approved');
});

it('refuses to total spend it cannot add, rather than understating it', function (): void {
    $setup = pacedWorkspace();

    // One day's rows switched to another currency, as a second ad account in another
    // market would produce.
    DailyMetric::query()
        ->where('entity_type', 'campaign')
        ->limit(1)
        ->update(['currency' => 'USD']);

    $pacing = $this->actingAs($setup['workspace']->scenario->owner)
        ->getJson("/api/budgets/{$setup['budgetId']}/pacing")
        ->json('data');

    /*
     * No total, and therefore no pacing. Pacing against the riyal rows alone would
     * understate spend — the one direction that gets a client overcharged without
     * anybody noticing.
     */
    expect($pacing['spend']['minor_units'])->toBeNull()
        ->and($pacing['spend']['unavailable_reason'])->toContain('cannot be added together')
        ->and($pacing['pacing'])->toBeNull();
});

it('refuses to convert spend reported in another currency', function (): void {
    $setup = pacedWorkspace(['currency' => 'USD', 'amount' => '20000.00']);

    $pacing = $this->actingAs($setup['workspace']->scenario->owner)
        ->getJson("/api/budgets/{$setup['budgetId']}/pacing")
        ->json('data');

    /*
     * An exchange rate is a business decision with a date and a source. Inventing one
     * here would put a made-up number next to a figure a client approved.
     */
    expect($pacing['spend']['minor_units'])->toBeNull()
        ->and($pacing['spend']['unavailable_reason'])->toContain('exchange rate');
});

it('reports no spend as not reported rather than zero', function (): void {
    // A period before anything was synced.
    $setup = pacedWorkspace([
        'period_start' => '2020-01-01',
        'period_end' => '2020-01-31',
    ]);

    $pacing = $this->actingAs($setup['workspace']->scenario->owner)
        ->getJson("/api/budgets/{$setup['budgetId']}/pacing")
        ->json('data');

    expect($pacing['spend']['minor_units'])->toBeNull()
        ->and($pacing['spend']['rows_reported'])->toBe(0)
        ->and($pacing['spend']['unavailable_reason'])->toContain('No spend')
        // The period is over, and nothing was reported in it. Not "on track".
        ->and($pacing['pacing'])->toBeNull();
});

it('compares each platform allocation with that platform’s own spend', function (): void {
    $setup = pacedWorkspace();

    $this->actingAs($setup['workspace']->scenario->owner)
        ->putJson("/api/budgets/{$setup['budgetId']}/allocations", [
            'allocations' => [
                ['level' => 'platform', 'provider' => 'meta', 'label' => 'Meta', 'amount' => '30000.00'],
                ['level' => 'platform', 'provider' => 'tiktok', 'label' => 'TikTok', 'amount' => '10000.00'],
            ],
        ])->assertOk();

    $rows = $this->actingAs($setup['workspace']->scenario->owner)
        ->getJson("/api/budgets/{$setup['budgetId']}/pacing")
        ->json('data.allocations');

    $meta = allocationRow($rows, 'provider', 'meta');
    $tiktok = allocationRow($rows, 'provider', 'tiktok');

    expect($meta['spend']['minor_units'])->toBeGreaterThan(0)
        /*
         * Nothing is synced for TikTok in this workspace, so its allocation has no spend
         * — reported as "not reported" rather than as a zero that would read as a
         * platform spending nothing when it is a platform nobody connected.
         */
        ->and($tiktok['spend']['minor_units'])->toBeNull()
        ->and($tiktok['spend']['rows_reported'])->toBe(0);
});

it('says plainly when a campaign allocation has no campaign to compare with', function (): void {
    $setup = pacedWorkspace();

    $this->actingAs($setup['workspace']->scenario->owner)
        ->putJson("/api/budgets/{$setup['budgetId']}/allocations", [
            'allocations' => [[
                'level' => 'platform',
                'provider' => 'meta',
                'label' => 'Meta',
                'amount' => '30000.00',
                'children' => [[
                    'level' => 'campaign',
                    'provider' => 'meta',
                    'label' => 'Planned, not built yet',
                    'amount' => '10000.00',
                ]],
            ]],
        ])->assertOk();

    $rows = $this->actingAs($setup['workspace']->scenario->owner)
        ->getJson("/api/budgets/{$setup['budgetId']}/pacing")
        ->json('data.allocations');

    $planned = allocationRow($rows, 'label', 'Planned, not built yet');

    /*
     * Planning a campaign before it exists is normal. There is nothing to compare it
     * with until it is created and linked, and saying so beats a zero that reads as "no
     * spend".
     */
    expect($planned['unavailable_reason'])->toContain('Not linked to a campaign')
        ->and($planned['pacing'])->toBeNull();
});

it('compares a linked campaign allocation with that campaign’s spend', function (): void {
    $setup = pacedWorkspace();
    $campaign = Campaign::query()->firstOrFail();

    $this->actingAs($setup['workspace']->scenario->owner)
        ->putJson("/api/budgets/{$setup['budgetId']}/allocations", [
            'allocations' => [[
                'level' => 'platform',
                'provider' => 'meta',
                'label' => 'Meta',
                'amount' => '30000.00',
                'children' => [[
                    'level' => 'campaign',
                    'provider' => 'meta',
                    'label' => (string) $campaign->getAttribute('name'),
                    'amount' => '10000.00',
                    'campaign_id' => (string) $campaign->getKey(),
                ]],
            ]],
        ])->assertOk();

    $rows = $this->actingAs($setup['workspace']->scenario->owner)
        ->getJson("/api/budgets/{$setup['budgetId']}/pacing")
        ->json('data.allocations');

    $linked = allocationRow($rows, 'level', 'campaign');

    $expected = (int) DailyMetric::query()
        ->where('entity_type', 'campaign')
        ->where('campaign_id', $campaign->getKey())
        ->sum('spend_minor');

    expect($linked['spend']['minor_units'])->toBe($expected)
        ->and($linked['pacing'])->not->toBeNull();
});

it('returns a day-by-day series for the pacing chart, with gaps left as gaps', function (): void {
    $setup = pacedWorkspace();

    $daily = $this->actingAs($setup['workspace']->scenario->owner)
        ->getJson("/api/budgets/{$setup['budgetId']}/pacing")
        ->json('data.daily');

    $distinctDays = DailyMetric::query()
        ->where('entity_type', 'campaign')
        ->where('currency', 'SAR')
        ->distinct()
        ->count('date');

    /*
     * One entry per day a provider actually reported. A day with nothing is absent
     * rather than zero, so a cumulative line is drawn through the gap instead of being
     * flattened by it.
     */
    expect($daily)->toHaveCount($distinctDays)
        ->and($daily[0])->toHaveKeys(['date', 'spendMinor']);
});

it('lists an overspending budget as an alert', function (): void {
    // A budget far smaller than the spend the fixtures carry.
    $setup = pacedWorkspace(['amount' => '100.00']);

    $alerts = $this->actingAs($setup['workspace']->scenario->owner)
        ->getJson('/api/budgets/alerts')
        ->assertOk()
        ->json('data');

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['pacing']['status'])->toBe(PacingStatus::Exhausted->value)
        ->and($alerts[0]['budget']['id'])->toBe($setup['budgetId']);
});

it('keeps a healthy budget out of the alert list', function (): void {
    $setup = pacedWorkspace();

    $start = now()->subDays(8)->format('Y-m-d');
    $end = now()->addDays(2)->format('Y-m-d');

    $spend = (int) DailyMetric::query()
        ->where('entity_type', 'campaign')
        ->where('currency', 'SAR')
        ->whereBetween('date', [$start, $end])
        ->sum('spend_minor');

    /*
     * A budget whose even pace lands exactly on the spend so far: nine days elapsed of
     * eleven, so the budget is the spend scaled by 11/9. Derived from what the sync
     * actually wrote rather than guessed, so the test does not break when the fixtures
     * change.
     */
    Budget::query()->whereKey($setup['budgetId'])->update([
        'amount_minor' => (int) round($spend * 11 / 9),
        'period_start' => $start,
        'period_end' => $end,
    ]);

    $alerts = $this->actingAs($setup['workspace']->scenario->owner)->getJson('/api/budgets/alerts')->json('data');

    expect($alerts)->toHaveCount(0);
});

it('lists a budget whose spend cannot be totalled, with its reason', function (): void {
    $setup = pacedWorkspace();

    DailyMetric::query()->where('entity_type', 'campaign')->limit(1)->update(['currency' => 'USD']);

    $alerts = $this->actingAs($setup['workspace']->scenario->owner)->getJson('/api/budgets/alerts')->json('data');

    /*
     * "We cannot tell you how this is pacing" is itself something an operator has to
     * know. Dropping these rows would make the list look reassuringly short.
     */
    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['pacing'])->toBeNull()
        ->and($alerts[0]['spend']['unavailable_reason'])->not->toBeNull();
});

it('never shows another workspace’s budget in the alerts', function (): void {
    pacedWorkspace();
    $other = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1002']);

    $this->actingAs($other->scenario->owner)->getJson('/api/budgets/alerts')->assertOk()->assertJsonCount(0, 'data');
});
