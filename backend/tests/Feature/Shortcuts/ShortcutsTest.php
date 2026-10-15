<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\DB;

/** @return array{0: Tests\Support\WorkspaceScenario, 1: Client, 2: Project} */
function shortcutWorld(): array
{
    $scenario = workspaceScenario();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey(), 'name' => 'Alhambra Retail']);
    $project = Project::factory()->forClient($client)->create(['name' => 'Ramadan']);

    return [$scenario, $client, $project];
}

it('pins a client and a project and lists them with readable labels', function (): void {
    [$scenario, $client, $project] = shortcutWorld();

    test()->actingAs($scenario->owner)->putJson("/api/shortcuts/pins/client/{$client->getKey()}")->assertNoContent();
    test()->actingAs($scenario->owner)->putJson("/api/shortcuts/pins/project/{$project->getKey()}")->assertNoContent();

    $pinned = test()->actingAs($scenario->owner)->getJson('/api/shortcuts')->assertOk()->json('data.pinned');

    expect(array_column($pinned, 'label'))->toBe(['Ramadan', 'Alhambra Retail'])
        ->and($pinned[0]['context'])->toBe('Alhambra Retail');
});

it('is idempotent to pin twice and to unpin what was never pinned', function (): void {
    [$scenario, $client] = shortcutWorld();

    test()->actingAs($scenario->owner)->putJson("/api/shortcuts/pins/client/{$client->getKey()}")->assertNoContent();
    test()->actingAs($scenario->owner)->putJson("/api/shortcuts/pins/client/{$client->getKey()}")->assertNoContent();
    test()->actingAs($scenario->owner)->deleteJson("/api/shortcuts/pins/client/{$client->getKey()}")->assertNoContent();
    test()->actingAs($scenario->owner)->deleteJson("/api/shortcuts/pins/client/{$client->getKey()}")->assertNoContent();

    expect(DB::table('user_pins')->count())->toBe(0);
});

it('keeps a bounded, most-recent-first list and never grows with how much is browsed', function (): void {
    [$scenario] = shortcutWorld();
    $ids = [];

    for ($i = 0; $i < 25; $i++) {
        $c = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey(), 'name' => sprintf('C%02d', $i)]);
        $ids[] = (string) $c->getKey();
        test()->actingAs($scenario->owner)->postJson('/api/shortcuts/recents', ['type' => 'client', 'id' => $c->getKey()])->assertNoContent();
    }

    expect(DB::table('user_recents')->count())->toBe(20);

    $recent = test()->actingAs($scenario->owner)->getJson('/api/shortcuts')->json('data.recent');
    expect($recent[0]['label'])->toBe('C24');
});

/* The safe direction to fail: a pin is a pointer, and what it points at is checked on every read. */
it('drops a shortcut to something the person can no longer see', function (): void {
    [$scenario, , $project] = shortcutWorld();
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);

    test()->actingAs($buyer)->putJson("/api/shortcuts/pins/project/{$project->getKey()}")->assertNoContent();

    expect(DB::table('user_pins')->count())->toBe(1);
    test()->actingAs($buyer)->getJson('/api/shortcuts')->assertOk()->assertJsonCount(0, 'data.pinned');
});

it('never shows one person another’s shortcuts', function (): void {
    [$scenario, $client] = shortcutWorld();
    $other = $scenario->addMember(SystemRole::ProjectManager);

    test()->actingAs($scenario->owner)->putJson("/api/shortcuts/pins/client/{$client->getKey()}")->assertNoContent();

    test()->actingAs($other)->getJson('/api/shortcuts')->assertOk()->assertJsonCount(0, 'data.pinned');
});

it('refuses a type it does not know', function (): void {
    [$scenario, $client] = shortcutWorld();

    test()->actingAs($scenario->owner)->putJson("/api/shortcuts/pins/lead/{$client->getKey()}")
        ->assertStatus(422)->assertJsonPath('code', 'invalid_type');
});
