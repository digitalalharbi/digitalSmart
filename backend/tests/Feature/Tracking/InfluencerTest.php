<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
| An influencer belongs to one client, and the links made for them carry their name. Nothing about money: no fee is
| recorded, so none is divided by. The rules worth pinning are who may see one, that a link can only name its own client's
| influencer, and that renaming the same person twice cannot split their results across two rows.
*/

const INFLUENCER_BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project} */
function influencerScene(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create();

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project];
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $scene
 * @param  array<string, mixed>  $overrides
 */
function addInfluencer(array $scene, array $overrides = []): string
{
    return (string) test()->actingAs($scene['scenario']->owner)->postJson('/api/influencers', [
        'client_id' => $scene['client']->getKey(),
        'name' => 'Noura Alharbi',
        'handle' => '@noura',
        'platform' => 'snapchat',
        ...$overrides,
    ])->assertCreated()->json('data.id');
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $scene
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function influencerLinkBody(array $scene, array $overrides = []): array
{
    return [
        'project_id' => $scene['project']->getKey(),
        'name' => 'Noura story',
        'destination_url' => 'https://shop.example.com/offer',
        'platform' => 'snapchat',
        'utm' => ['utm_source' => 'snapchat', 'utm_medium' => 'influencer', 'utm_campaign' => 'spring'],
        ...$overrides,
    ];
}

it('adds an influencer to a client, lists it, and refuses the same name twice whatever its case', function (): void {
    $scene = influencerScene();
    $id = addInfluencer($scene);

    $list = test()->actingAs($scene['scenario']->owner)->getJson('/api/influencers')->assertOk()->json('data');

    expect($list)->toHaveCount(1)
        ->and($list[0]['id'])->toBe($id)
        ->and($list[0]['client']['id'])->toBe((string) $scene['client']->getKey())
        ->and($list[0]['totals'])->toBe(['links' => 0, 'clicks' => 0, 'visitors' => 0]);

    test()->actingAs($scene['scenario']->owner)->postJson('/api/influencers', [
        'client_id' => $scene['client']->getKey(), 'name' => '  noura alharbi ',
    ])->assertStatus(422);

    // Another client may have an influencer of the same name.
    $other = Client::factory()->create(['workspace_id' => $scene['scenario']->workspace->getKey()]);
    Project::factory()->forClient($other)->create();
    test()->actingAs($scene['scenario']->owner)->postJson('/api/influencers', ['client_id' => $other->getKey(), 'name' => 'Noura Alharbi'])->assertCreated();
});

it('archives without deleting, and hides an archived influencer unless asked', function (): void {
    $scene = influencerScene();
    $id = addInfluencer($scene);

    test()->actingAs($scene['scenario']->owner)->putJson("/api/influencers/{$id}", [
        'client_id' => $scene['client']->getKey(), 'name' => 'Noura Alharbi', 'status' => 'archived',
    ])->assertOk()->assertJsonPath('data.status', 'archived');

    expect(test()->actingAs($scene['scenario']->owner)->getJson('/api/influencers')->json('data'))->toBe([]);
    expect(test()->actingAs($scene['scenario']->owner)->getJson('/api/influencers?archived=1')->json('data'))->toHaveCount(1);
    expect(DB::table('influencers')->where('id', $id)->count())->toBe(1);
});

it('never moves an influencer to another client', function (): void {
    $scene = influencerScene();
    $id = addInfluencer($scene);
    $other = Client::factory()->create(['workspace_id' => $scene['scenario']->workspace->getKey()]);
    Project::factory()->forClient($other)->create();

    test()->actingAs($scene['scenario']->owner)->putJson("/api/influencers/{$id}", [
        'client_id' => $other->getKey(), 'name' => 'Noura Alharbi',
    ])->assertStatus(422);

    expect((string) DB::table('influencers')->where('id', $id)->value('client_id'))->toBe((string) $scene['client']->getKey());
});

it('shows a reader only the influencers of clients they can see, and refuses a role with no tracking permission', function (): void {
    $scene = influencerScene();
    $mine = addInfluencer($scene);
    $other = Client::factory()->create(['workspace_id' => $scene['scenario']->workspace->getKey()]);
    $otherProject = Project::factory()->forClient($other)->create();
    $theirs = addInfluencer(['scenario' => $scene['scenario'], 'client' => $other, 'project' => $otherProject], ['name' => 'Someone else']);

    $buyer = $scene['scenario']->addMember(SystemRole::MediaBuyer);
    ProjectMember::query()->create([
        'project_id' => $scene['project']->getKey(),
        'user_id' => $buyer->getKey(),
        'role_id' => $scene['scenario']->role(SystemRole::MediaBuyer)->getKey(),
    ]);

    $visible = array_column(test()->actingAs($buyer)->getJson('/api/influencers')->assertOk()->json('data'), 'id');
    expect($visible)->toBe([$mine]);
    test()->actingAs($buyer)->getJson("/api/influencers/{$theirs}")->assertNotFound();
    test()->actingAs($buyer)->postJson('/api/influencers', ['client_id' => $other->getKey(), 'name' => 'Sneaky'])->assertNotFound();

    $viewer = $scene['scenario']->addMember(SystemRole::ClientViewer);
    test()->actingAs($viewer)->getJson('/api/influencers')->assertForbidden();
    test()->actingAs($viewer)->postJson('/api/influencers', ['client_id' => $scene['client']->getKey(), 'name' => 'No'])->assertForbidden();
});

it('lets a link name its own client\'s influencer, and refuses another client\'s or another workspace\'s as not found', function (): void {
    $scene = influencerScene();
    $mine = addInfluencer($scene);

    $link = test()->actingAs($scene['scenario']->owner)
        ->postJson('/api/tracking/links', influencerLinkBody($scene, ['influencer_id' => $mine]))
        ->assertCreated()->json('data');

    expect($link['influencer'])->toBe(['id' => $mine, 'name' => 'Noura Alharbi']);

    $other = Client::factory()->create(['workspace_id' => $scene['scenario']->workspace->getKey()]);
    $otherProject = Project::factory()->forClient($other)->create();
    $theirs = addInfluencer(['scenario' => $scene['scenario'], 'client' => $other, 'project' => $otherProject], ['name' => 'Someone else']);

    test()->actingAs($scene['scenario']->owner)
        ->postJson('/api/tracking/links', influencerLinkBody($scene, ['influencer_id' => $theirs]))
        ->assertNotFound();

    $stranger = influencerScene();
    $foreign = addInfluencer($stranger, ['name' => 'Foreign']);

    test()->actingAs($scene['scenario']->owner)
        ->postJson('/api/tracking/links', influencerLinkBody($scene, ['influencer_id' => $foreign]))
        ->assertNotFound();
});

it('sums valid clicks over an influencer\'s links and nothing else', function (): void {
    $scene = influencerScene();
    $id = addInfluencer($scene);
    $owner = $scene['scenario']->owner;

    $first = test()->actingAs($owner)->postJson('/api/tracking/links', influencerLinkBody($scene, ['influencer_id' => $id]))->assertCreated()->json('data');
    $second = test()->actingAs($owner)->postJson('/api/tracking/links', influencerLinkBody($scene, ['name' => 'Noura reel', 'influencer_id' => $id]))->assertCreated()->json('data');
    test()->actingAs($owner)->postJson('/api/tracking/links', influencerLinkBody($scene, ['name' => 'Nobody']))->assertCreated();

    foreach ([basename((string) $first['short_url']), basename((string) $first['short_url']), basename((string) $second['short_url'])] as $token) {
        test()->withHeaders(['User-Agent' => INFLUENCER_BROWSER])->get("/api/r/{$token}")->assertRedirect();
    }

    $totals = test()->actingAs($owner)->getJson("/api/influencers/{$id}")->assertOk()->json('data.totals');

    expect($totals['links'])->toBe(2)
        ->and($totals['clicks'])->toBe(3);
});

it('records a revision when a link\'s influencer changes, and none when it never had one', function (): void {
    $scene = influencerScene();
    $id = addInfluencer($scene);
    $owner = $scene['scenario']->owner;

    $link = (string) test()->actingAs($owner)->postJson('/api/tracking/links', influencerLinkBody($scene))->assertCreated()->json('data.id');

    // Saved again with no influencer: nothing changed, so no revision.
    test()->actingAs($owner)->putJson("/api/tracking/links/{$link}", influencerLinkBody($scene))->assertOk();
    expect(test()->actingAs($owner)->getJson("/api/tracking/links/{$link}/history")->json('data'))->toHaveCount(1);

    test()->actingAs($owner)->putJson("/api/tracking/links/{$link}", influencerLinkBody($scene, ['influencer_id' => $id]))->assertOk();
    expect(test()->actingAs($owner)->getJson("/api/tracking/links/{$link}/history")->json('data'))->toHaveCount(2);

    test()->actingAs($owner)->putJson("/api/tracking/links/{$link}", influencerLinkBody($scene))->assertOk()->assertJsonPath('data.influencer', null);
    expect(test()->actingAs($owner)->getJson("/api/tracking/links/{$link}/history")->json('data'))->toHaveCount(3);
});
