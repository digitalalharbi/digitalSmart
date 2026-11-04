<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Tests\Support\WorkspaceScenario;

/*
| The campaign-parameter audit: findings about what was typed, scoped exactly like the link list.
*/

/** @return array{0: WorkspaceScenario, 1: Project} */
function utmAuditProject(?WorkspaceScenario $scenario = null): array
{
    $scenario ??= WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

    return [$scenario, Project::factory()->forClient($client)->create()];
}

/**
 * @param  array<string, string>  $utm
 * @return array<string, mixed>
 */
function utmAuditCreate(object $as, Project $project, string $name, array $utm, string $url = 'https://shop.example.com/offer'): array
{
    /** @var array<string, mixed> $link */
    $link = test()->actingAs($as)->postJson('/api/tracking/links', [
        'project_id' => $project->getKey(), 'name' => $name, 'destination_url' => $url, 'utm' => $utm,
    ])->assertCreated()->json('data');

    return $link;
}

/** @return array<string, mixed> */
function utmAuditRead(object $as, string $query = ''): array
{
    /** @var array<string, mixed> $data */
    $data = test()->actingAs($as)->getJson('/api/tracking/links/audit'.$query)->assertOk()->json('data');

    return $data;
}

it('counts what it audited and lists only the links with something wrong', function (): void {
    [$scenario, $project] = utmAuditProject();
    $owner = $scenario->owner;
    utmAuditCreate($owner, $project, 'Clean', ['utm_source' => 'snapchat', 'utm_medium' => 'paid_social', 'utm_campaign' => 'spring']);
    $bad = utmAuditCreate($owner, $project, 'No medium', ['utm_source' => 'snapchat', 'utm_campaign' => 'summer']);

    $data = utmAuditRead($owner);

    expect($data['audited'])->toBe(2)
        ->and($data['with_findings'])->toBe(1)
        ->and($data['by_code'])->toBe(['missing_medium' => 1])
        ->and($data['links'][0]['id'])->toBe($bad['id'])
        ->and($data['links'][0]['findings'][0]['code'])->toBe('missing_medium');
});

it('reports two live links carrying the same set, and stops once one is paused', function (): void {
    [$scenario, $project] = utmAuditProject();
    $owner = $scenario->owner;
    $utm = ['utm_source' => 'snapchat', 'utm_medium' => 'paid_social', 'utm_campaign' => 'spring'];
    $first = utmAuditCreate($owner, $project, 'First', $utm);
    $second = utmAuditCreate($owner, $project, 'Second', $utm);

    expect(utmAuditRead($owner)['by_code'])->toBe(['duplicate_set' => 2]);

    test()->actingAs($owner)->postJson("/api/tracking/links/{$second['id']}/status", ['status' => 'paused'])->assertSuccessful();

    expect(utmAuditRead($owner)['with_findings'])->toBe(0)
        ->and($first['id'])->not->toBe($second['id']);
});

it('keeps another workspace’s links out of the audit, and out of a duplicate', function (): void {
    [$scenario, $project] = utmAuditProject();
    [$other, $theirs] = utmAuditProject();
    $utm = ['utm_source' => 'snapchat', 'utm_medium' => 'paid_social', 'utm_campaign' => 'spring'];
    utmAuditCreate($scenario->owner, $project, 'Ours', $utm);
    utmAuditCreate($other->owner, $theirs, 'Theirs', ['utm_source' => 'snapchat']);

    $data = utmAuditRead($scenario->owner, '?project_id='.$theirs->getKey());
    $ours = utmAuditRead($scenario->owner);

    expect($data['audited'])->toBe(0)
        ->and($ours['audited'])->toBe(1)
        ->and($ours['with_findings'])->toBe(0);
});

it('shows a member only the projects they are on', function (): void {
    [$scenario, $project] = utmAuditProject();
    utmAuditCreate($scenario->owner, $project, 'No medium', ['utm_source' => 'snapchat', 'utm_campaign' => 'summer']);
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);

    expect(utmAuditRead($buyer)['audited'])->toBe(0);
});

it('is refused without tracking access', function (): void {
    [$scenario] = utmAuditProject();

    test()->actingAs($scenario->addMember(SystemRole::ClientViewer))->getJson('/api/tracking/links/audit')->assertForbidden();
});
