<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use Tests\Support\SyncedWorkspace;

/** @return array{primary: ?string, profiles: list<array{key: string, label: string, sections: list<string>}>} */
function profilesFor(SystemRole $role): array
{
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $user = $role === SystemRole::Owner ? $w->scenario->owner : $w->scenario->addMember($role);

    return test()->actingAs($user)->getJson('/api/home')->assertOk()->json('data');
}

it('gives an owner the agency home first', function (): void {
    expect(profilesFor(SystemRole::Owner)['primary'])->toBe('executive');
});

it('gives a project manager the projects home, and a media buyer the campaigns home', function (): void {
    expect(profilesFor(SystemRole::ProjectManager)['primary'])->toBe('project_manager')
        ->and(profilesFor(SystemRole::MediaBuyer)['primary'])->toBe('media_buyer');
});

it('gives a creative the creative home and no money', function (): void {
    $data = profilesFor(SystemRole::Creative);

    expect($data['primary'])->toBe('creative')
        ->and(array_column($data['profiles'], 'key'))->toBe(['creative']);
});

it('gives a client user no agency home at all', function (): void {
    expect(profilesFor(SystemRole::ClientViewer))->toBe(['primary' => null, 'profiles' => []]);
});

it('narrows "my campaigns" to assigned projects, and an empty assignment is none rather than everything', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $pm = $w->scenario->addMember(SystemRole::ProjectManager);

    $all = test()->actingAs($pm)->getJson('/api/campaigns/control')->json('meta.matched');
    $mine = test()->actingAs($pm)->getJson('/api/campaigns/control?mine=1')->assertOk()->json('meta.matched');

    expect($all)->toBeGreaterThan(0)->and($mine)->toBe(0);
});
