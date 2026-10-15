<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

/** @return array{0: SyncedWorkspace, 1: App\Infrastructure\Persistence\Eloquent\Model\Project} */
function readyWorld(): array
{
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    return [$w, $w->project(Provider::Meta)];
}

it('answers seven questions, and is blocked until a budget exists', function (): void {
    [$w, $project] = readyWorld();

    $data = test()->actingAs($w->scenario->owner)->getJson("/api/projects/{$project->getKey()}/readiness")->assertOk()->json('data');
    $states = array_column($data['checks'], 'state', 'key');

    expect($data['checks'])->toHaveCount(7)
        ->and($states['budget'])->toBe('blocked')
        ->and($states['accounts'])->toBe('ready')
        ->and($data['overall']['state'])->toBe('blocked');
});

/* The product does not model landing pages. Saying "ready" about it would be the one lie to avoid. */
it('says "not tracked" about what it cannot see, and never lets it make a project ready or blocked', function (): void {
    [$w, $project] = readyWorld();

    $data = test()->actingAs($w->scenario->owner)->getJson("/api/projects/{$project->getKey()}/readiness")->json('data');
    $checks = array_column($data['checks'], null, 'key');

    expect($checks['landing_page']['state'])->toBe('unknown')->and($checks['landing_page']['label'])->toBe('Not tracked');
});

it('is ready on budget once an approved budget covers today', function (): void {
    [$w, $project] = readyWorld();
    $owner = $w->scenario->owner;

    $id = test()->actingAs($owner)->postJson('/api/budgets', [
        'client_id' => (string) $project->getAttribute('client_id'), 'project_id' => (string) $project->getKey(),
        'name' => 'Now', 'period_start' => now()->subDays(5)->format('Y-m-d'), 'period_end' => now()->addDays(20)->format('Y-m-d'),
        'amount' => '1000.00', 'currency' => 'SAR',
    ])->json('data.id');
    test()->actingAs($owner)->postJson("/api/budgets/{$id}/approve")->assertOk();

    $states = array_column(
        test()->actingAs($owner)->getJson("/api/projects/{$project->getKey()}/readiness")->json('data.checks'),
        'state',
        'key',
    );

    expect($states['budget'])->toBe('ready');
});

it('is a 404 for a project the reader is not on', function (): void {
    [$w, $project] = readyWorld();
    $buyer = $w->scenario->addMember(SystemRole::MediaBuyer);

    test()->actingAs($buyer)->getJson("/api/projects/{$project->getKey()}/readiness")->assertNotFound();
});

it('flags an account whose timezone or currency is unknown, rather than assuming one', function (): void {
    [$w, $project] = readyWorld();
    DB::table('external_accounts')->whereIn('id', DB::table('project_integration_bindings')->where('project_id', $project->getKey())->pluck('external_account_id'))
        ->update(['timezone' => null, 'currency' => null]);

    $checks = array_column(
        test()->actingAs($w->scenario->owner)->getJson("/api/projects/{$project->getKey()}/readiness")->json('data.checks'),
        null,
        'key',
    );

    expect($checks['data_quality']['state'])->toBe('needs_attention')
        ->and($checks['data_quality']['reason'])->toContain('no currency')->toContain('no timezone');
});

it('is ready on data quality when currency and timezone are both known', function (): void {
    [$w, $project] = readyWorld();
    DB::table('external_accounts')->whereIn('id', DB::table('project_integration_bindings')->where('project_id', $project->getKey())->pluck('external_account_id'))
        ->update(['timezone' => 'Asia/Riyadh', 'currency' => 'SAR']);

    $checks = array_column(
        test()->actingAs($w->scenario->owner)->getJson("/api/projects/{$project->getKey()}/readiness")->json('data.checks'),
        null,
        'key',
    );

    expect($checks['data_quality']['state'])->toBe('ready');
});
