<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Symfony\Component\HttpFoundation\Response;

/** @return array{0: Tests\Support\WorkspaceScenario, 1: Client, 2: Project} */
function searchWorld(): array
{
    $scenario = workspaceScenario();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey(), 'name' => 'Alhambra Retail']);
    $project = Project::factory()->forClient($client)->create(['name' => 'Alhambra Ramadan']);
    $other = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey(), 'name' => 'Alhambra Rival']);
    Project::factory()->forClient($other)->create(['name' => 'Alhambra Secret']);

    return [$scenario, $client, $project];
}

it('finds clients and projects across the agency in one request', function (): void {
    [$scenario] = searchWorld();

    $hits = $this->actingAs($scenario->owner)->getJson('/api/search?q=alhambra')->assertOk()->json('data');

    expect(array_column($hits, 'type'))->toContain('client', 'project')
        ->and(array_column($hits, 'label'))->toContain('Alhambra Retail', 'Alhambra Secret');
});

it('ranks a prefix match above a substring match', function (): void {
    [$scenario] = searchWorld();
    Client::factory()->create(['workspace_id' => $scenario->workspace->getKey(), 'name' => 'The Alhambra Annex']);

    $labels = array_column(
        $this->actingAs($scenario->owner)->getJson('/api/search?q=alhambra&type[]=client')->json('data'),
        'label',
    );

    expect($labels[count($labels) - 1])->toBe('The Alhambra Annex');
});

it('does not return another project to somebody who is not on it', function (): void {
    [$scenario, , $project] = searchWorld();
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);
    $this->actingAs($scenario->owner)->postJson("/api/projects/{$project->getKey()}/members", [
        'user_id' => $buyer->getKey(), 'role' => SystemRole::MediaBuyer->value,
    ])->assertSuccessful();

    $labels = array_column($this->actingAs($buyer)->getJson('/api/search?q=alhambra')->json('data'), 'label');

    expect($labels)->toContain('Alhambra Ramadan')
        ->and($labels)->not->toContain('Alhambra Secret')
        ->and($labels)->not->toContain('Alhambra Rival');
});

it('returns nothing to a client user, and treats a wildcard as text', function (): void {
    [$scenario] = searchWorld();
    $viewer = $scenario->addMember(SystemRole::ClientViewer);

    $this->actingAs($viewer)->getJson('/api/search?q=alhambra')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($scenario->owner)->getJson('/api/search?q=%25')->assertOk()->assertJsonCount(0, 'data');
});

it('refuses an unknown type', function (): void {
    [$scenario] = searchWorld();

    $this->actingAs($scenario->owner)->getJson('/api/search?q=a&type[]=weather')
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)->assertJsonPath('code', 'invalid_type');
});
