<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Lead;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use Tests\Support\WorkspaceScenario;

/*
| "All / my clients / my projects / my leads" on the central lead list.
| A scope only ever narrows: the cases that matter are the ones where it might widen.
*/

/** @return array{s: WorkspaceScenario, mine: Project, other: Project, buyer: App\Infrastructure\Persistence\Eloquent\Model\User} */
function scopedPipeline(): array
{
    $s = WorkspaceScenario::create();
    $ws = $s->workspace->getKey();
    $clientA = Client::factory()->create(['workspace_id' => $ws]);
    $clientB = Client::factory()->create(['workspace_id' => $ws]);
    $mine = Project::factory()->forClient($clientA)->create();
    $other = Project::factory()->forClient($clientB)->create();
    $buyer = $s->addMember(SystemRole::MediaBuyer);

    ProjectMember::query()->create(['project_id' => $mine->getKey(), 'user_id' => $buyer->getKey(), 'role_id' => $s->role(SystemRole::MediaBuyer)->getKey()]);

    return compact('s', 'mine', 'other', 'buyer');
}

/**
 * @return list<string>
 */
function leadIds(mixed $rows, bool $sorted = false): array
{
    $ids = [];

    foreach (is_array($rows) ? $rows : [] as $row) {
        if (is_array($row) && is_string($row['id'] ?? null)) {
            $ids[] = $row['id'];
        }
    }

    if ($sorted) {
        sort($ids);
    }

    return $ids;
}

/** @param  array<string, mixed>  $attributes */
function scopedLead(Project $project, array $attributes = []): Lead
{
    /** @var Lead */
    return Lead::query()->create([
        'workspace_id' => $project->getAttribute('workspace_id'),
        'client_id' => $project->getAttribute('client_id'),
        'project_id' => $project->getKey(),
        'source_kind' => 'form_submission',
        'name' => 'Lead '.uniqid(),
        'stage' => 'new',
        'received_at' => new DateTimeImmutable('-1 day'),
        ...$attributes,
    ]);
}

it('narrows to the projects, clients and leads that are the reader’s own', function (): void {
    ['s' => $s, 'mine' => $mine, 'other' => $other, 'buyer' => $buyer] = scopedPipeline();
    $owned = scopedLead($mine, ['owner_id' => $buyer->getKey()]);
    $unowned = scopedLead($mine);
    $elsewhere = scopedLead($other);

    $ids = fn (string $scope): array => leadIds($this->actingAs($s->owner)->getJson('/api/leads?scope='.$scope)->assertOk()->json('data'));

    // The owner is a member of nothing, so "my" scopes are empty rather than falling back to everything.
    expect($ids('all'))->toHaveCount(3)
        ->and($ids('my_projects'))->toBe([])
        ->and($ids('my_clients'))->toBe([])
        ->and($ids('mine'))->toBe([]);

    $mineIds = fn (string $scope): array => leadIds($this->actingAs($buyer)->getJson('/api/leads?scope='.$scope)->assertOk()->json('data'), true);
    $both = leadIds([['id' => $owned->getKey()], ['id' => $unowned->getKey()]], true);

    expect($mineIds('my_projects'))->toBe($both)
        ->and($mineIds('my_clients'))->toBe($both)
        ->and($mineIds('mine'))->toBe([$owned->getKey()])
        ->and($mineIds('all'))->not->toContain($elsewhere->getKey());
});

it('filters by project, owner, source and campaign on top of visibility', function (): void {
    ['s' => $s, 'mine' => $mine, 'other' => $other, 'buyer' => $buyer] = scopedPipeline();
    $a = scopedLead($mine, ['owner_id' => $buyer->getKey(), 'utm_campaign' => 'ramadan', 'source_kind' => 'whatsapp_message']);
    scopedLead($mine, ['utm_campaign' => 'summer']);
    scopedLead($other, ['utm_campaign' => 'ramadan']);

    $get = fn (string $q): array => leadIds($this->actingAs($s->owner)->getJson('/api/leads?'.$q)->assertOk()->json('data'));

    expect($get('campaign=ramadan'))->toHaveCount(2)
        ->and($get('campaign=ramadan&project_id='.$mine->getKey()))->toBe([$a->getKey()])
        ->and($get('owner_id='.$buyer->getKey()))->toBe([$a->getKey()])
        ->and($get('source_kind=whatsapp_message'))->toBe([$a->getKey()]);
});

it('never widens a client viewer past the project they hold', function (): void {
    ['s' => $s, 'mine' => $mine, 'other' => $other] = scopedPipeline();
    scopedLead($mine);
    scopedLead($other);
    $viewer = $s->addMember(SystemRole::ClientViewer);
    ProjectMember::query()->create(['project_id' => $mine->getKey(), 'user_id' => $viewer->getKey(), 'role_id' => $s->role(SystemRole::ClientViewer)->getKey()]);

    $response = $this->actingAs($viewer)->getJson('/api/leads?scope=all&project_id='.$other->getKey());

    // Either refused outright or empty — what it must never be is the other client's lead.
    if ($response->status() === 200) {
        expect($response->json('data'))->toBe([]);
    } else {
        $response->assertForbidden();
    }
});

it('scopes the centre’s figures to the same people as the list', function (): void {
    ['s' => $s, 'mine' => $mine, 'other' => $other, 'buyer' => $buyer] = scopedPipeline();
    scopedLead($mine, ['owner_id' => $buyer->getKey()]);
    scopedLead($mine);
    scopedLead($other);

    $total = fn (string $q) => $this->actingAs($buyer)->getJson('/api/leads/centre?'.$q)->assertOk()->json('data.funnel.total');

    expect($total('scope=all'))->toBe(2)
        ->and($total('scope=mine'))->toBe(1)
        ->and($total('scope=my_projects'))->toBe(2);

    // The owner belongs to no project, so their "my projects" is empty rather than the whole agency.
    expect($this->actingAs($s->owner)->getJson('/api/leads/centre?scope=my_projects')->assertOk()->json('data.funnel.total'))->toBe(0);
});
