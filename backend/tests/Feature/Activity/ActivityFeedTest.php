<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

function activityEntry(WorkspaceScenario $s, AuditAction $action, string $type, string $id, string $summary): void
{
    DB::table('audit_logs')->insert([
        'id' => (string) Illuminate\Support\Str::uuid(), 'workspace_id' => $s->workspace->getKey(), 'actor_id' => $s->owner->getKey(),
        'action' => $action->value, 'auditable_type' => $type, 'auditable_id' => $id, 'summary' => $summary,
        'changes' => '{}', 'context' => '{}', 'created_at' => now(),
    ]);
}

it('shows a media buyer the events of their projects and nobody else’s', function (): void {
    $s = WorkspaceScenario::create();
    $ws = $s->workspace->getKey();
    $mine = Project::factory()->forClient(Client::factory()->create(['workspace_id' => $ws]))->create();
    $theirs = Project::factory()->forClient(Client::factory()->create(['workspace_id' => $ws]))->create();
    activityEntry($s, AuditAction::ProjectStatusChanged, 'project', (string) $mine->getKey(), 'Ramadan moved to active');
    activityEntry($s, AuditAction::ProjectStatusChanged, 'project', (string) $theirs->getKey(), 'Secret client project moved to active');

    $buyer = $s->addMember(SystemRole::MediaBuyer);
    ProjectMember::query()->create(['project_id' => $mine->getKey(), 'user_id' => $buyer->getKey(), 'role_id' => $s->role(SystemRole::MediaBuyer)->getKey()]);

    $text = fn ($user): string => implode('|', array_column($this->actingAs($user)->getJson('/api/activity')->assertOk()->json('data'), 'sentence'));

    expect($text($buyer))->toContain('Ramadan moved to active');
    expect(str_contains($text($buyer), 'Secret client'))->toBeFalse();
    expect($text($s->owner))->toContain('Secret client project moved to active');
});

it('leaves out events that belong to the audit trail, not the work', function (): void {
    $s = WorkspaceScenario::create();
    $project = Project::factory()->forClient(Client::factory()->create(['workspace_id' => $s->workspace->getKey()]))->create();
    activityEntry($s, AuditAction::IntegrationCredentialsUpdated, 'provider_connection', (string) $project->getKey(), 'Credentials updated');
    activityEntry($s, AuditAction::UserLoggedIn, 'user', (string) $s->owner->getKey(), 'Signed in');

    expect($this->actingAs($s->owner)->getJson('/api/activity')->assertOk()->json('data'))->toBe([]);
});

it('is the agency’s: a client viewer is refused', function (): void {
    $s = WorkspaceScenario::create();
    $project = Project::factory()->forClient(Client::factory()->create(['workspace_id' => $s->workspace->getKey()]))->create();
    $viewer = $s->addMember(SystemRole::ClientViewer);
    ProjectMember::query()->create(['project_id' => $project->getKey(), 'user_id' => $viewer->getKey(), 'role_id' => $s->role(SystemRole::ClientViewer)->getKey()]);
    activityEntry($s, AuditAction::ProjectStatusChanged, 'project', (string) $project->getKey(), 'Moved to active');

    $this->actingAs($viewer)->getJson('/api/activity')->assertForbidden();
});

it('places a task’s event with its project, so scope applies to it', function (): void {
    $s = WorkspaceScenario::create();
    $ws = $s->workspace->getKey();
    $mine = Project::factory()->forClient(Client::factory()->create(['workspace_id' => $ws]))->create();
    $theirs = Project::factory()->forClient(Client::factory()->create(['workspace_id' => $ws]))->create();
    // Opened through the API, which is the only writer and records the audit entry itself.
    $this->actingAs($s->owner)->postJson('/api/tasks', [
        'client_id' => (string) $theirs->getAttribute('client_id'), 'project_id' => (string) $theirs->getKey(),
        'title' => 'Hidden task', 'priority' => 'normal',
    ])->assertSuccessful();
    $buyer = $s->addMember(SystemRole::MediaBuyer);
    ProjectMember::query()->create(['project_id' => $mine->getKey(), 'user_id' => $buyer->getKey(), 'role_id' => $s->role(SystemRole::MediaBuyer)->getKey()]);

    expect($this->actingAs($buyer)->getJson('/api/activity')->json('data'))->toBe([])
        ->and($this->actingAs($s->owner)->getJson('/api/activity')->json('data'))->not->toBeEmpty();
});

it('reads one project’s events even when the agency has had a great many since', function (): void {
    $s = WorkspaceScenario::create();
    $ws = $s->workspace->getKey();
    $quiet = Project::factory()->forClient(Client::factory()->create(['workspace_id' => $ws]))->create();
    $busy = Project::factory()->forClient(Client::factory()->create(['workspace_id' => $ws]))->create();

    activityEntry($s, AuditAction::ProjectStatusChanged, 'project', (string) $quiet->getKey(), 'The quiet one moved');

    foreach (range(1, 120) as $n) {
        activityEntry($s, AuditAction::ProjectStatusChanged, 'project', (string) $busy->getKey(), 'Busy event '.$n);
    }

    // Ask for the agency's last thirty and the quiet project's one event is not among them; ask for it and it is.
    $agency = array_column($this->actingAs($s->owner)->getJson('/api/activity?limit=30')->json('data'), 'sentence');
    $scoped = array_column($this->actingAs($s->owner)->getJson('/api/activity?limit=30&project_id='.$quiet->getKey())->json('data'), 'sentence');

    expect($agency)->not->toContain('The quiet one moved')
        ->and($scoped)->toBe(['The quiet one moved']);
});
