<?php

declare(strict_types=1);

use App\Application\System\Contract\DeploymentRecord;
use App\Domain\Access\SystemRole;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\SyncedWorkspace;

/*
 * Do GitHub, main and the installation agree — read from the public API, cached, and for administrators only.
 */
function fakeGitHub(string $status, ?string $conclusion, string $sha = 'dc05b45aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): void
{
    Cache::flush();
    Http::fake(['api.github.com/*' => Http::response(['workflow_runs' => [
        ['name' => 'Deploy Production', 'status' => 'completed', 'conclusion' => 'success', 'head_sha' => $sha],
        ['name' => 'CI', 'status' => $status, 'conclusion' => $conclusion, 'head_sha' => $sha],
    ]])]);
}

function deployedAs(?string $sha): void
{
    app()->instance(DeploymentRecord::class, new class($sha) implements DeploymentRecord
    {
        public function __construct(private readonly ?string $sha) {}

        public function deployedSha(): ?string
        {
            return $this->sha;
        }
    });
}

it('reports main green and deployment waiting when nothing was ever deployed', function (): void {
    fakeGitHub('completed', 'success');
    deployedAs(null);
    $w = SyncedWorkspace::with([]);

    $data = test()->actingAs($w->scenario->owner)->getJson('/api/system/sync-status')->assertOk()->json('data');

    expect($data['main'])->toBe(['state' => 'green', 'label' => 'green', 'sha' => 'dc05b45'])
        ->and($data['deploy'])->toBe(['state' => 'grey', 'label' => 'waiting', 'sha' => null]);
});

it('says running, failed and unknown as amber, red and grey', function (string $status, ?string $conclusion, string $state): void {
    fakeGitHub($status, $conclusion);
    deployedAs(null);
    $w = SyncedWorkspace::with([]);

    expect(test()->actingAs($w->scenario->owner)->getJson('/api/system/sync-status')->json('data.main.state'))->toBe($state);
})->with([
    'running' => ['in_progress', null, 'amber'],
    'failed' => ['completed', 'failure', 'red'],
    'cancelled' => ['completed', 'cancelled', 'grey'],
]);

it('shows live when the deployed commit is main, and behind when main has moved on', function (): void {
    fakeGitHub('completed', 'success');
    $w = SyncedWorkspace::with([]);

    deployedAs('dc05b45');
    expect(test()->actingAs($w->scenario->owner)->getJson('/api/system/sync-status')->json('data.deploy.label'))->toBe('live');

    deployedAs('abcdef0');
    expect(test()->actingAs($w->scenario->owner)->getJson('/api/system/sync-status')->json('data.deploy'))
        ->toMatchArray(['state' => 'amber', 'label' => 'behind main']);
});

it('answers unknown rather than green when GitHub cannot be read', function (): void {
    Cache::flush();
    Http::fake(['api.github.com/*' => Http::response('rate limited', 403)]);
    deployedAs(null);
    $w = SyncedWorkspace::with([]);

    expect(test()->actingAs($w->scenario->owner)->getJson('/api/system/sync-status')->json('data.main'))
        ->toBe(['state' => 'grey', 'label' => 'unknown', 'sha' => null]);
});

it('asks GitHub once however many times it is opened', function (): void {
    fakeGitHub('completed', 'success');
    deployedAs(null);
    $w = SyncedWorkspace::with([]);

    test()->actingAs($w->scenario->owner)->getJson('/api/system/sync-status')->assertOk();
    test()->actingAs($w->scenario->owner)->getJson('/api/system/sync-status')->assertOk();

    Http::assertSentCount(1);
});

it('is not for a role that cannot see workspace settings', function (): void {
    fakeGitHub('completed', 'success');
    $w = SyncedWorkspace::with([]);
    $viewer = $w->scenario->addMember(SystemRole::ClientViewer);

    test()->actingAs($viewer)->getJson('/api/system/sync-status')->assertForbidden();
});
