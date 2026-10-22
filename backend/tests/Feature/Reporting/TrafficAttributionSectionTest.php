<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Eloquent\Model\Client;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| The clicks-and-funnel section and the attributed-revenue section
|--------------------------------------------------------------------------
|
| What is asserted: the funnel is a fixed set of stages that no author can reorder or drop; attribution is read for one
| client, names its revenue authority, is internal only, and travels with how much of the period it could explain.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client} */
function trafficScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

    return ['scenario' => $scenario, 'client' => $client];
}

it('saves and renders a funnel section with its fixed stages', function (): void {
    $setup = trafficScenario();

    $id = (string) $this->actingAs($setup['scenario']->owner)
        ->postJson('/api/reports', [
            'name' => 'Funnel',
            'window' => 'last_30_days',
            'sections' => [['kind' => 'traffic']],
        ])
        ->assertCreated()
        ->json('data.id');

    $section = $this->actingAs($setup['scenario']->owner)
        ->getJson("/api/reports/{$id}")
        ->assertOk()
        ->json('data.sections.0');

    expect($section['kind'])->toBe('traffic')
        ->and($section['metrics'])->toBe(['impressions', 'clicks', 'link_clicks', 'conversions', 'ctr', 'cpc', 'conversion_rate'])
        ->and($section['attribution'])->toBeNull();
});

it('ignores any metric list a funnel section is sent, so a stage cannot be dropped or reordered', function (): void {
    $setup = trafficScenario();

    $id = (string) $this->actingAs($setup['scenario']->owner)
        ->postJson('/api/reports', [
            'name' => 'Funnel',
            'window' => 'last_30_days',
            'sections' => [['kind' => 'traffic', 'metrics' => ['spend']]],
        ])
        ->assertCreated()
        ->json('data.id');

    $metrics = $this->actingAs($setup['scenario']->owner)
        ->getJson("/api/reports/{$id}")
        ->json('data.sections.0.metrics');

    expect($metrics[0])->toBe('impressions')->and($metrics)->not->toContain('spend');
});

it('refuses attributed revenue without a client', function (): void {
    $setup = trafficScenario();

    $this->actingAs($setup['scenario']->owner)
        ->postJson('/api/reports', [
            'name' => 'Attribution',
            'window' => 'last_30_days',
            'sections' => [['kind' => 'attribution', 'revenue_source' => 'salla']],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'attribution_needs_client');
});

it('refuses attributed revenue that does not name whose orders it counts', function (): void {
    $setup = trafficScenario();

    $this->actingAs($setup['scenario']->owner)
        ->postJson('/api/reports', [
            'name' => 'Attribution',
            'client_id' => (string) $setup['client']->getKey(),
            'window' => 'last_30_days',
            'audience' => 'internal',
            'sections' => [['kind' => 'attribution']],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'attribution_needs_source');
});

it('keeps attributed revenue out of a report written for a client', function (): void {
    $setup = trafficScenario();

    $this->actingAs($setup['scenario']->owner)
        ->postJson('/api/reports', [
            'name' => 'Attribution',
            'client_id' => (string) $setup['client']->getKey(),
            'window' => 'last_30_days',
            'audience' => 'client_safe',
            'sections' => [['kind' => 'attribution', 'revenue_source' => 'salla']],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'attribution_not_client_safe');
});

it('renders attributed revenue with its coverage and says when nothing was recorded', function (): void {
    $setup = trafficScenario();

    $id = (string) $this->actingAs($setup['scenario']->owner)
        ->postJson('/api/reports', [
            'name' => 'Attribution',
            'client_id' => (string) $setup['client']->getKey(),
            'window' => 'last_30_days',
            'audience' => 'internal',
            'sections' => [['kind' => 'attribution', 'revenue_source' => 'salla']],
        ])
        ->assertCreated()
        ->json('data.id');

    $section = $this->actingAs($setup['scenario']->owner)
        ->getJson("/api/reports/{$id}")
        ->assertOk()
        ->json('data.sections.0');

    expect($section['kind'])->toBe('attribution')
        ->and($section['is_empty'])->toBeTrue()
        ->and($section['attribution']['model'])->toBe('last_recorded')
        ->and($section['attribution']['is_modelled'])->toBeFalse()
        ->and($section['attribution']['revenue_source'])->toBe('salla')
        ->and($section['attribution']['coverage'])->toHaveKeys(['conversions', 'revenue_coverage', 'is_reportable', 'reportable_percent'])
        ->and($section['attribution']['rows'])->toBe([]);
});

it('exports both new sections as csv without failing', function (): void {
    $setup = trafficScenario();

    $id = (string) $this->actingAs($setup['scenario']->owner)
        ->postJson('/api/reports', [
            'name' => 'Both',
            'client_id' => (string) $setup['client']->getKey(),
            'window' => 'last_30_days',
            'audience' => 'internal',
            'sections' => [['kind' => 'traffic'], ['kind' => 'attribution', 'revenue_source' => 'salla']],
        ])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($setup['scenario']->owner)->get("/api/reports/{$id}/csv")->assertOk();
    $this->actingAs($setup['scenario']->owner)->get("/api/reports/{$id}/xlsx")->assertOk();
});
