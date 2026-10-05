<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Advertising\MetricSource;
use App\Domain\Budget\Forecast\Confidence;
use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\DailyMetric;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\SyncedWorkspace;

/*
|--------------------------------------------------------------------------
| Budget forecasting
|--------------------------------------------------------------------------
|
| Forecasts come from the workspace's own delivery and nothing else. There is no
| industry-benchmark table in this product, so most of these tests are about what the
| calculator refuses to do when the data is thin — which is the only reason an estimate
| from it can be shown to a client at all.
|
*/

it('estimates results from the workspace’s own observed rates', function (): void {
    $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    $data = $this->actingAs($workspace->scenario->owner)
        ->postJson('/api/budgets/forecast', ['budget' => '50000.00', 'providers' => ['meta']])
        ->assertOk()
        ->json('data');

    $meta = $data['forecasts'][0];

    expect($meta['provider'])->toBe('meta')
        ->and($meta['projection']['clicks'])->toBeGreaterThan(0)
        ->and($meta['projection']['impressions'])->toBeGreaterThan(0)
        // Which observed rate each figure came from, in words.
        ->and($meta['projection']['assumptions'])->not->toBeEmpty();
});

it('labels every projection as an estimate', function (): void {
    $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    $data = $this->actingAs($workspace->scenario->owner)
        ->postJson('/api/budgets/forecast', ['budget' => '50000.00'])
        ->json('data');

    /*
     * The source label travels with the projection so an estimate cannot be read as a
     * measurement anywhere downstream — and the rates' own source is reported separately,
     * because fixture-derived rates are a different caveat from the estimate itself.
     */
    expect($data['forecasts'][0]['source'])->toBe(MetricSource::Estimated->value)
        ->and($data['forecasts'][0]['rate_source'])->toBe(MetricSource::Sandbox->value);
});

it('publishes the sample behind the rates', function (): void {
    $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    $data = $this->actingAs($workspace->scenario->owner)
        ->postJson('/api/budgets/forecast', ['budget' => '50000.00'])
        ->json('data');

    // A figure quoted in a meeting has to be reproducible later.
    expect($data['benchmarks']['version'])->toStartWith('own-data/')
        ->and($data['benchmarks']['source'])->toContain('No industry averages')
        ->and($data['benchmarks']['window_start'])->not->toBeNull()
        ->and($data['benchmarks']['providers'][0]['observed_days'])->toBeGreaterThan(0)
        ->and($data['disclaimer'])->toContain('not guarantees');
});

it('carries a band on every figure rather than a single number', function (): void {
    $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    $projection = $this->actingAs($workspace->scenario->owner)
        ->postJson('/api/budgets/forecast', ['budget' => '50000.00'])
        ->json('data.forecasts.0.projection');

    /*
     * The band is the 25th to 75th percentile of the account's own daily cost — a real
     * statistic about its variation rather than a percentage chosen to look like a range.
     */
    expect($projection['clicks_low'])->toBeLessThanOrEqual($projection['clicks'])
        ->and($projection['clicks_high'])->toBeGreaterThanOrEqual($projection['clicks']);
});

it('works a budget back from a conversion target', function (): void {
    $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    $projection = $this->actingAs($workspace->scenario->owner)
        ->postJson('/api/budgets/forecast', [
            'target_metric' => 'conversions',
            'target_value' => 100,
            'providers' => ['meta'],
        ])
        ->assertOk()
        ->json('data.forecasts.0.projection');

    expect($projection['required_budget_minor'])->toBeGreaterThan(0)
        ->and((float) $projection['conversions'])->toBe(100.0)
        ->and($projection['clicks'])->toBeGreaterThan(0)
        // The cheaper rate needs the smaller budget, so the band crosses over.
        ->and($projection['required_budget_low_minor'])->toBeLessThanOrEqual($projection['required_budget_minor']);
});

it('works a budget back from a click target', function (): void {
    $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    $projection = $this->actingAs($workspace->scenario->owner)
        ->postJson('/api/budgets/forecast', [
            'target_metric' => 'clicks',
            'target_value' => 1000,
        ])
        ->json('data.forecasts.0.projection');

    expect($projection['required_budget_minor'])->toBeGreaterThan(0)
        ->and($projection['clicks'])->toBe(1000);
});

it('refuses to estimate a platform with too little delivery', function (): void {
    $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    // Leave two days of delivery, which is below the minimum sample.
    $keep = DailyMetric::query()
        ->where('entity_type', 'campaign')
        ->distinct()
        ->orderByDesc('date')
        ->limit(2)
        ->pluck('date')
        ->all();

    DailyMetric::query()->whereNotIn('date', $keep)->delete();

    $forecast = $this->actingAs($workspace->scenario->owner)
        ->postJson('/api/budgets/forecast', ['budget' => '50000.00'])
        ->json('data.forecasts.0');

    /*
     * No projection at all. The alternative — a number from two days of one campaign,
     * presented like any other — is the kind of estimate that gets quoted back as a
     * commitment.
     */
    expect($forecast['projection'])->toBeNull()
        ->and($forecast['confidence'])->toBe(Confidence::None->value)
        ->and($forecast['unavailable_reason'])->toContain('day');
});

it('says which figures it could not work out', function (): void {
    $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    // A workspace whose conversion tracking reports nothing.
    DailyMetric::query()->update(['conversions' => null]);

    $forecast = $this->actingAs($workspace->scenario->owner)
        ->postJson('/api/budgets/forecast', ['budget' => '50000.00'])
        ->json('data.forecasts.0');

    expect($forecast['projection']['clicks'])->toBeGreaterThan(0)
        // Named, rather than rendered as an empty field someone reads as zero.
        ->and($forecast['projection']['conversions'])->toBeNull()
        ->and(implode(' ', $forecast['gaps']))->toContain('conversion rate');
});

it('refuses a conversion target when no conversion rate has been observed', function (): void {
    $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    DailyMetric::query()->update(['conversions' => null]);

    $forecast = $this->actingAs($workspace->scenario->owner)
        ->postJson('/api/budgets/forecast', [
            'target_metric' => 'conversions',
            'target_value' => 100,
        ])
        ->json('data.forecasts.0');

    expect($forecast['projection']['required_budget_minor'])->toBeNull()
        ->and(implode(' ', $forecast['gaps']))->toContain('cannot be worked out');
});

it('refuses to derive a rate from two currencies', function (): void {
    $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    DailyMetric::query()->where('entity_type', 'campaign')->limit(2)->update(['currency' => 'USD']);

    $benchmark = $this->actingAs($workspace->scenario->owner)
        ->postJson('/api/budgets/forecast', ['budget' => '50000.00'])
        ->json('data.benchmarks.providers.0');

    /*
     * A cost per click averaged across currencies is not a cost. No rate, and the reason
     * says which currencies collided.
     */
    expect($benchmark['rates']['cpc_minor'])->toBeNull()
        ->and($benchmark['unavailable_reason'])->toContain('currencies');
});

it('needs either a budget or a target', function (): void {
    $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    $this->actingAs($workspace->scenario->owner)
        ->postJson('/api/budgets/forecast', [])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors('budget');
});

it('needs the budget permission', function (): void {
    $workspace = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $creative = $workspace->scenario->addMember(SystemRole::Creative);

    $this->actingAs($creative)
        ->postJson('/api/budgets/forecast', ['budget' => '50000.00'])
        ->assertForbidden();
});

it('never derives rates from another workspace’s delivery', function (): void {
    SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $other = SyncedWorkspace::with([Provider::GoogleAds->value => 'sandbox-100-200-3000']);

    $data = $this->actingAs($other->scenario->owner)
        ->postJson('/api/budgets/forecast', ['budget' => '50000.00'])
        ->json('data');

    $providers = array_column($data['benchmarks']['providers'], 'provider');

    // Only its own platform, whatever the other workspace has been running.
    expect($providers)->toBe(['google_ads']);
});
