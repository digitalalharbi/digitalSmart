<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;

/**
 * A board card names the people and the client it belongs to.
 *
 * `TaskView` has carried `clientName`, `projectName` and `assigneeName` since the board was built, the
 * API resource publishes all three, and the board query selected none of them: `filtered()` builds on
 * `visible()`, which returns a bare `tasks` builder with no joins, so every card came back with three
 * nulls where names belong. The detail view joined correctly, which is why opening a card showed what
 * the card itself could not.
 *
 * Nothing caught it because no test had asserted on a card's names — only on its title and state.
 */
it('names the assignee, the client and the project on every board card', function (): void {
    $scenario = workspaceScenario();
    $client = Client::factory()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'name' => 'Alhambra Retail',
    ]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Ramadan Campaign']);

    test()->actingAs($scenario->owner)
        ->postJson("/api/projects/{$project->getKey()}/members", [
            'user_id' => $scenario->owner->getKey(),
            'role' => SystemRole::ProjectManager->value,
        ])
        ->assertSuccessful();

    $this->actingAs($scenario->owner)
        ->postJson('/api/tasks', [
            'client_id' => (string) $client->getKey(),
            'project_id' => (string) $project->getKey(),
            'title' => 'Rebuild the audience lists',
            'assignee_id' => (string) $scenario->owner->getKey(),
        ])
        ->assertCreated();

    $card = $this->actingAs($scenario->owner)->getJson('/api/tasks/board')
        ->assertOk()
        ->json('data.columns.0.tasks.0');

    expect($card['assignee_name'])->toBe($scenario->owner->name)
        ->and($card['client_name'])->toBe('Alhambra Retail')
        ->and($card['project_name'])->toBe('Ramadan Campaign');
});
