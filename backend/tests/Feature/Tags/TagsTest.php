<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use Tests\Support\SyncedWorkspace;

it('creates a tag once and refuses a duplicate', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $owner = $w->scenario->owner;

    test()->actingAs($owner)->postJson('/api/tags', ['name' => 'Ramadan'])->assertCreated()->assertJsonPath('data.slug', 'ramadan');
    test()->actingAs($owner)->postJson('/api/tags', ['name' => 'ramadan'])->assertStatus(422)->assertJsonPath('code', 'tag_exists');
});

/* A tag on a project reaches its campaigns, so tagging the work does not mean tagging each campaign by hand. */
it('filters campaigns by a tag on the campaign or on its project', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001', Provider::GoogleAds->value => 'act_sandbox_2001']);
    $owner = $w->scenario->owner;
    $project = $w->project(Provider::Meta);

    $tag = test()->actingAs($owner)->postJson('/api/tags', ['name' => 'Ramadan'])->json('data.id');
    test()->actingAs($owner)->putJson("/api/tags/{$tag}/entities/project/{$project->getKey()}")->assertNoContent();

    $all = test()->actingAs($owner)->getJson('/api/campaigns/control?limit=200')->json('meta.matched');
    $tagged = test()->actingAs($owner)->getJson("/api/campaigns/control?tag_id[]={$tag}&limit=200")->json('data');

    expect($tagged)->not->toBeEmpty()
        ->and(count($tagged))->toBeLessThan($all)
        ->and(array_unique(array_column($tagged, 'provider')))->toBe(['meta']);
});

it('refuses to tag something the person cannot see, as if it did not exist', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $owner = $w->scenario->owner;
    $buyer = $w->scenario->addMember(SystemRole::MediaBuyer);
    $project = $w->project(Provider::Meta);

    $tag = test()->actingAs($owner)->postJson('/api/tags', ['name' => 'Priority'])->json('data.id');

    test()->actingAs($buyer)->putJson("/api/tags/{$tag}/entities/project/{$project->getKey()}")->assertNotFound();
});

it('is internal: a client user cannot list tags', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $viewer = $w->scenario->addMember(SystemRole::ClientViewer);

    test()->actingAs($viewer)->getJson('/api/tags')->assertForbidden();
});

it('stops filtering once the tag is removed', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $owner = $w->scenario->owner;
    $project = $w->project(Provider::Meta);
    $tag = test()->actingAs($owner)->postJson('/api/tags', ['name' => 'Always-on'])->json('data.id');

    test()->actingAs($owner)->putJson("/api/tags/{$tag}/entities/project/{$project->getKey()}")->assertNoContent();
    test()->actingAs($owner)->deleteJson("/api/tags/{$tag}/entities/project/{$project->getKey()}")->assertNoContent();

    test()->actingAs($owner)->getJson("/api/campaigns/control?tag_id[]={$tag}")->assertOk()->assertJsonCount(0, 'data');
});

it('shows a card the tags it inherits from its project', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $owner = $w->scenario->owner;
    $project = $w->project(Provider::Meta);
    $tag = test()->actingAs($owner)->postJson('/api/tags', ['name' => 'High spend'])->json('data.id');
    test()->actingAs($owner)->putJson("/api/tags/{$tag}/entities/project/{$project->getKey()}")->assertNoContent();

    $row = test()->actingAs($owner)->getJson('/api/campaigns/control')->json('data.0');
    expect(array_column($row['tags'], 'name'))->toBe(['High spend']);
});
