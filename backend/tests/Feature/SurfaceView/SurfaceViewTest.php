<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use Tests\Support\WorkspaceScenario;

/**
 * @return array{s: WorkspaceScenario, a: Project, b: Project}
 */
function viewWorld(): array
{
    $s = WorkspaceScenario::create();
    $ws = $s->workspace->getKey();
    $a = Project::factory()->forClient(Client::factory()->create(['workspace_id' => $ws]))->create();
    $b = Project::factory()->forClient(Client::factory()->create(['workspace_id' => $ws]))->create();

    return ['s' => $s, 'a' => $a, 'b' => $b];
}

function joinProject(WorkspaceScenario $s, Project $project, SystemRole $role): App\Infrastructure\Persistence\Eloquent\Model\User
{
    $user = $s->addMember($role);
    ProjectMember::query()->create(['project_id' => $project->getKey(), 'user_id' => $user->getKey(), 'role_id' => $s->role($role)->getKey()]);

    return $user;
}

it('keeps a private view private, shows a workspace view to everyone, and a team view to the team', function (): void {
    ['s' => $s, 'a' => $a, 'b' => $b] = viewWorld();
    $onA = joinProject($s, $a, SystemRole::MediaBuyer);
    $onB = joinProject($s, $b, SystemRole::MediaBuyer);

    $this->actingAs($onA)->postJson('/api/views/campaign_control', ['name' => 'Mine', 'filters' => ['health' => ['critical']]])->assertCreated();
    $this->actingAs($onA)->postJson('/api/views/campaign_control', ['name' => 'Everyone', 'visibility' => 'workspace', 'filters' => ['status' => 'active']])->assertCreated();
    $this->actingAs($onA)->postJson('/api/views/campaign_control', ['name' => 'Team A', 'visibility' => 'project', 'project_id' => (string) $a->getKey(), 'filters' => ['q' => 'ramadan']])->assertCreated();

    $names = fn ($user): array => array_column($this->actingAs($user)->getJson('/api/views/campaign_control')->assertOk()->json('data'), 'name');

    expect($names($onA))->toEqualCanonicalizing(['Mine', 'Everyone', 'Team A'])
        ->and($names($onB))->toBe(['Everyone']);
});

it('refuses to aim a team view at a project the saver is not on', function (): void {
    ['s' => $s, 'a' => $a, 'b' => $b] = viewWorld();
    $onA = joinProject($s, $a, SystemRole::MediaBuyer);

    $this->actingAs($onA)->postJson('/api/views/leads', ['name' => 'Sneaky', 'visibility' => 'project', 'project_id' => (string) $b->getKey()])->assertNotFound();
});

it('keeps only the filters a surface offers, and only as plain strings', function (): void {
    ['s' => $s] = viewWorld();

    $view = $this->actingAs($s->owner)->postJson('/api/views/budget_control', [
        'name' => 'Pacing', 'filters' => ['risk' => 'overspending', 'drop_table' => 'x', 'q' => ['a', ['nested'], 'b'], 'client' => ['deep' => ['x']]],
    ])->assertCreated()->json('data.filters');

    // JSONB does not keep key order, so equality rather than identity.
    expect($view)->toEqual(['risk' => 'overspending', 'q' => ['a', 'b']]);
});

it('does not let a name be used twice, and lets only the author delete', function (): void {
    ['s' => $s] = viewWorld();
    $owner = $s->owner;
    $other = $s->addMember(SystemRole::ProjectManager);

    $id = $this->actingAs($owner)->postJson('/api/views/leads', ['name' => 'Hot', 'visibility' => 'workspace'])->assertCreated()->json('data.id');
    $this->actingAs($owner)->postJson('/api/views/leads', ['name' => 'Hot'])->assertStatus(422)->assertJsonPath('code', 'name_already_used');

    $this->actingAs($other)->deleteJson("/api/views/{$id}")->assertNotFound();
    $this->actingAs($owner)->deleteJson("/api/views/{$id}")->assertNoContent();
});

it('closes the agency surfaces’ saved views to a client viewer', function (): void {
    ['s' => $s, 'a' => $a] = viewWorld();
    $this->actingAs($s->owner)->postJson('/api/views/leads', ['name' => 'Internal', 'visibility' => 'workspace'])->assertCreated();
    $viewer = joinProject($s, $a, SystemRole::ClientViewer);

    // A client viewer cannot read the agency's saved filters on the campaign surface either.
    $this->actingAs($viewer)->getJson('/api/views/campaign_control')->assertForbidden();
    $this->actingAs($viewer)->postJson('/api/views/campaign_control', ['name' => 'x'])->assertForbidden();
});

it('answers an unknown surface plainly', function (): void {
    ['s' => $s] = viewWorld();

    $this->actingAs($s->owner)->getJson('/api/views/nonsense')->assertNotFound()->assertJsonPath('code', 'unknown_surface');
});

/*
 * A view is a link that was kept, so every filter the Campaigns page puts in its address must survive being saved —
 * otherwise "Ending soon" or "Budget risk" can be shown but not kept, and a saved view quietly opens wider than it was.
 */
it('keeps every filter the campaigns page puts in its address', function (): void {
    ['s' => $s] = viewWorld();
    $filters = [
        'client' => 'c~Client', 'project' => 'p~Project', 'account' => 'a~Account', 'tag' => 't~Tag',
        'mine' => '1', 'days' => '14', 'provider' => 'meta', 'status' => 'active', 'objective' => 'leads',
        'health' => 'critical', 'ends' => '7', 'budget' => 'high', 'zero' => '1', 'q' => 'ramadan',
        'sort' => 'spend', 'dir' => 'desc', 'view' => 'cards',
    ];

    $kept = $this->actingAs($s->owner)->postJson('/api/views/campaign_control', [
        'name' => 'Everything', 'filters' => $filters,
    ])->assertCreated()->json('data.filters');

    expect($kept)->toEqual($filters);
});

it('saves a view of the ad groups screen, with the filters that screen offers and no others', function (): void {
    ['s' => $s] = viewWorld();

    $kept = $this->actingAs($s->owner)->postJson('/api/views/ad_groups', [
        'name' => 'Spending, no results',
        'filters' => ['campaign' => 'c~Campaign', 'status' => 'active', 'zero' => '1', 'sort' => 'roas', 'ends' => '7'],
    ])->assertCreated()->json('data.filters');

    /* `ends` is the campaigns screen's filter; an ad group has no end date of its own to offer. */
    expect($kept)->toEqual(['campaign' => 'c~Campaign', 'status' => 'active', 'zero' => '1', 'sort' => 'roas']);
});
