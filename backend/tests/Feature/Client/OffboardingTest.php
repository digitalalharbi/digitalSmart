<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

/** @return array{0: SyncedWorkspace, 1: string} */
function leavingClient(): array
{
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001', Provider::GoogleAds->value => 'act_sandbox_2001']);

    return [$w, (string) $w->project(Provider::Meta)->getAttribute('client_id')];
}

it('shows what leaving will stop before anything is done', function (): void {
    [$w, $client] = leavingClient();

    $preview = test()->actingAs($w->scenario->owner)->getJson("/api/clients/{$client}/offboarding")->assertOk()->json('data');

    expect($preview['bindings_paused'])->toBe(1)
        ->and($preview['projects_closing'])->toBeGreaterThan(0);

    expect(DB::table('project_integration_bindings')->where('sync_enabled', true)->count())->toBe(2);
});

it('stops recurring work and keeps every figure, report and audit entry', function (): void {
    [$w, $client] = leavingClient();
    $before = [
        'metrics' => DB::table('daily_metrics')->count(),
        'campaigns' => DB::table('campaigns')->count(),
        'audit' => DB::table('audit_logs')->count(),
    ];

    test()->actingAs($w->scenario->owner)->postJson("/api/clients/{$client}/offboard", ['reason' => 'Contract ended'])
        ->assertOk()->assertJsonPath('data.bindings_paused', 1);

    expect(DB::table('clients')->where('id', $client)->value('status'))->toBe('offboarded')
        ->and(DB::table('daily_metrics')->count())->toBe($before['metrics'])
        ->and(DB::table('campaigns')->count())->toBe($before['campaigns'])
        ->and(DB::table('audit_logs')->count())->toBeGreaterThan($before['audit']);

    /* Their account stopped syncing; the other client's did not. */
    expect(DB::table('project_integration_bindings')->where('sync_enabled', true)->count())->toBe(1);
});

it('stops the scheduler spending anything on an offboarded client', function (): void {
    [$w, $client] = leavingClient();
    test()->actingAs($w->scenario->owner)->postJson("/api/clients/{$client}/offboard", ['reason' => 'Left'])->assertOk();

    $candidates = app(App\Application\Integration\Contract\SyncCandidateSource::class)->candidates();
    $planned = (new App\Domain\Integration\Sync\SyncPlanner)->plan($candidates, new DateTimeImmutable('+2 days'));

    expect(count($planned))->toBe(1);
});

it('requires a reason, refuses a second offboarding, and is the agency’s to do', function (): void {
    [$w, $client] = leavingClient();
    $owner = $w->scenario->owner;

    test()->actingAs($owner)->postJson("/api/clients/{$client}/offboard", [])->assertStatus(422);
    test()->actingAs($owner)->postJson("/api/clients/{$client}/offboard", ['reason' => 'Left'])->assertOk();
    test()->actingAs($owner)->postJson("/api/clients/{$client}/offboard", ['reason' => 'Again'])->assertOk();

    $creative = $w->scenario->addMember(SystemRole::Creative);
    test()->actingAs($creative)->postJson("/api/clients/{$client}/offboard", ['reason' => 'Nope, not mine'])->assertForbidden();
});

it('lets an offboarded client return, which archiving would not', function (): void {
    [$w, $client] = leavingClient();
    $owner = $w->scenario->owner;

    test()->actingAs($owner)->postJson("/api/clients/{$client}/offboard", ['reason' => 'Left'])->assertOk();
    test()->actingAs($owner)->patchJson("/api/clients/{$client}/status", ['status' => 'active'])->assertOk();
    test()->actingAs($owner)->patchJson("/api/clients/{$client}/status", ['status' => 'archived'])->assertOk();
    test()->actingAs($owner)->patchJson("/api/clients/{$client}/status", ['status' => 'active'])->assertStatus(422);
});
