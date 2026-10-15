<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use Database\Seeders\AgencyScaleSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

it('says nothing about a group with fewer than three clients', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    $data = test()->actingAs($w->scenario->owner)->getJson('/api/benchmarks')->assertOk()->json('data');

    expect($data['groups'])->not->toBeEmpty();

    foreach ($data['groups'] as $group) {
        expect($group['suppressed'])->toBeTrue()->and($group)->not->toHaveKey('median');
    }
});

it('publishes quartiles once enough clients stand behind a group', function (): void {
    config(['agency_scale.clients' => 9, 'agency_scale.projects_per_client' => 1, 'agency_scale.accounts' => 12,
        'agency_scale.campaigns' => 60, 'agency_scale.days' => 14]);
    app(AgencyScaleSeeder::class)->run();
    $owner = App\Infrastructure\Persistence\Eloquent\Model\User::query()->where('email', AgencyScaleSeeder::OWNER_EMAIL)->sole();
    /* The seeder leaves every campaign unassigned from sync's point of view but attaches projects through bindings. */
    DB::table('campaigns')->update(['status' => 'active']);

    $data = test()->actingAs($owner)->getJson('/api/benchmarks')->assertOk()->json('data');
    $open = array_values(array_filter($data['groups'], static fn (array $g): bool => ! $g['suppressed']));

    /* Not vacuous: with nine clients behind a few providers, some group must clear the threshold. */
    expect($open)->not->toBeEmpty();

    foreach ($open as $g) {
        expect($g['clients'])->toBeGreaterThanOrEqual(3)
            ->and($g['p25'])->toBeLessThanOrEqual($g['median'])
            ->and($g['median'])->toBeLessThanOrEqual($g['p75']);
    }
});

it('never groups cost across currencies', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    $groups = test()->actingAs($w->scenario->owner)->getJson('/api/benchmarks')->json('data.groups');

    foreach ($groups as $g) {
        if ($g['metric'] !== 'roas') {
            expect($g['currency'])->not->toBeNull();
        }
    }
});

it('is closed to a client user and to a creative', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    test()->actingAs($w->scenario->addMember(SystemRole::ClientViewer))->getJson('/api/benchmarks')->assertForbidden();
    test()->actingAs($w->scenario->addMember(SystemRole::Creative))->getJson('/api/benchmarks')->assertForbidden();
});
