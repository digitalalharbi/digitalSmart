<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Domain\Project\ProjectStatus;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WorkspaceScenario;

function assign(WorkspaceScenario $scenario, Project $project, User $user, SystemRole $role = SystemRole::MediaBuyer): void
{
    ProjectMember::query()->create([
        'project_id' => $project->getKey(),
        'user_id' => $user->getKey(),
        'role_id' => $scenario->role($role)->getKey(),
    ]);
}

it('requires authentication', function (): void {
    $this->getJson('/api/projects')->assertUnauthorized();
});

describe('visibility', function (): void {
    it('shows every project to a role with workspace-wide visibility', function (): void {
        $scenario = workspaceScenario();
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
        Project::factory()->count(3)->forClient($client)->create();

        $this->actingAs($scenario->owner)
            ->getJson('/api/projects')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('shows a media buyer only the projects they are assigned to', function (): void {
        $scenario = workspaceScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

        $mine = Project::factory()->forClient($client)->create(['name' => 'Assigned work']);
        Project::factory()->forClient($client)->create(['name' => 'Someone else’s work']);
        assign($scenario, $mine, $buyer);

        $response = $this->actingAs($buyer)->getJson('/api/projects');

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Assigned work')
            ->assertJsonPath('data.0.is_assigned', true);
    });

    it('shows an unassigned buyer nothing at all, rather than everything', function (): void {
        $scenario = workspaceScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
        Project::factory()->count(2)->forClient($client)->create();

        // An empty assignment list must mean "none", never "no filter".
        $this->actingAs($buyer)
            ->getJson('/api/projects')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    });

    it('reports an unassigned project as not found for a buyer', function (): void {
        $scenario = workspaceScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
        $other = Project::factory()->forClient($client)->create();

        $this->actingAs($buyer)
            ->getJson("/api/projects/{$other->getKey()}")
            ->assertNotFound();
    });

    it('never leaks a project from another workspace', function (): void {
        $mine = workspaceScenario('Mine');
        $theirs = workspaceScenario('Theirs');
        $foreignClient = Client::factory()->create(['workspace_id' => $theirs->workspace->getKey()]);
        $foreign = Project::factory()->forClient($foreignClient)->create();

        $this->actingAs($mine->owner)
            ->getJson("/api/projects/{$foreign->getKey()}")
            ->assertNotFound();
    });
});

describe('creating', function (): void {
    it('creates a project in planning for a client of this workspace', function (): void {
        $scenario = workspaceScenario();
        $manager = $scenario->addMember(SystemRole::ProjectManager);
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

        $response = $this->actingAs($manager)->postJson('/api/projects', [
            'client_id' => $client->getKey(),
            'name' => 'Ramadan Campaign',
            'currency' => 'sar',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', ProjectStatus::Planning->value)
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.client.id', (string) $client->getKey());

        expect(AuditLog::query()->where('action', AuditAction::ProjectCreated->value)->exists())->toBeTrue();
    });

    it('refuses a client from another workspace', function (): void {
        $mine = workspaceScenario('Mine');
        $theirs = workspaceScenario('Theirs');
        $foreignClient = Client::factory()->create(['workspace_id' => $theirs->workspace->getKey()]);

        $this->actingAs($mine->owner)
            ->postJson('/api/projects', [
                'client_id' => $foreignClient->getKey(),
                'name' => 'Cross-tenant project',
                'currency' => 'SAR',
            ])
            ->assertNotFound();

        expect(Project::query()->count())->toBe(0);
    });

    it('refuses creation to a media buyer', function (): void {
        $scenario = workspaceScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

        $this->actingAs($buyer)
            ->postJson('/api/projects', [
                'client_id' => $client->getKey(),
                'name' => 'Unauthorised',
                'currency' => 'SAR',
            ])
            ->assertForbidden()
            ->assertJsonPath('required_permission', 'projects.create');
    });

    it('rejects an end date before the start date', function (): void {
        $scenario = workspaceScenario();
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

        $this->actingAs($scenario->owner)
            ->postJson('/api/projects', [
                'client_id' => $client->getKey(),
                'name' => 'Backwards',
                'currency' => 'SAR',
                'starts_on' => '2026-06-01',
                'ends_on' => '2026-05-01',
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('ends_on');
    });
});

describe('status', function (): void {
    it('rejects an illegal transition with the domain error code', function (): void {
        $scenario = workspaceScenario();
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
        $project = Project::factory()->forClient($client)->status(ProjectStatus::Archived)->create();

        $this->actingAs($scenario->owner)
            ->patchJson("/api/projects/{$project->getKey()}/status", ['status' => 'active'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'invalid_status_transition');
    });

    it('records whether spend is still permitted after the change', function (): void {
        $scenario = workspaceScenario();
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
        $project = Project::factory()->forClient($client)->status(ProjectStatus::Active)->create();

        $this->actingAs($scenario->owner)
            ->patchJson("/api/projects/{$project->getKey()}/status", ['status' => 'paused'])
            ->assertOk();

        $entry = AuditLog::query()->where('action', AuditAction::ProjectStatusChanged->value)->first();
        expect($entry?->getAttribute('changes'))->toMatchArray(['allows_spend' => false]);
    });

    it('refuses a buyer acting on a project they are not assigned to', function (): void {
        $scenario = workspaceScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
        $project = Project::factory()->forClient($client)->status(ProjectStatus::Active)->create();

        $this->actingAs($buyer)
            ->patchJson("/api/projects/{$project->getKey()}/status", ['status' => 'paused'])
            ->assertForbidden();
    });

    it('lets an assigned buyer update the project', function (): void {
        $scenario = workspaceScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
        $project = Project::factory()->forClient($client)->status(ProjectStatus::Active)->create();
        assign($scenario, $project, $buyer);

        $this->actingAs($buyer)
            ->patchJson("/api/projects/{$project->getKey()}/status", ['status' => 'paused'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paused');
    });
});

describe('members', function (): void {
    it('assigning someone grants them visibility of the project', function (): void {
        $scenario = workspaceScenario();
        $manager = $scenario->addMember(SystemRole::ProjectManager);
        $creative = $scenario->addMember(SystemRole::Creative);
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
        $project = Project::factory()->forClient($client)->create();

        $this->actingAs($creative)->getJson('/api/projects')->assertJsonCount(0, 'data');

        $this->actingAs($manager)
            ->postJson("/api/projects/{$project->getKey()}/members", [
                'user_id' => $creative->getKey(),
                'role' => SystemRole::Creative->value,
            ])
            ->assertNoContent();

        $this->actingAs($creative)->getJson('/api/projects')->assertJsonCount(1, 'data');
    });

    it('removing someone revokes it again', function (): void {
        $scenario = workspaceScenario();
        $manager = $scenario->addMember(SystemRole::ProjectManager);
        $creative = $scenario->addMember(SystemRole::Creative);
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
        $project = Project::factory()->forClient($client)->create();
        assign($scenario, $project, $creative, SystemRole::Creative);

        $this->actingAs($manager)
            ->deleteJson("/api/projects/{$project->getKey()}/members/{$creative->getKey()}")
            ->assertNoContent();

        $this->actingAs($creative)->getJson('/api/projects')->assertJsonCount(0, 'data');
    });

    it('refuses to assign someone from outside the workspace', function (): void {
        $scenario = workspaceScenario('Mine');
        $outsider = workspaceScenario('Theirs')->owner;
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
        $project = Project::factory()->forClient($client)->create();

        $this->actingAs($scenario->owner)
            ->postJson("/api/projects/{$project->getKey()}/members", [
                'user_id' => $outsider->getKey(),
                'role' => SystemRole::Creative->value,
            ])
            ->assertNotFound();
    });

    it('refuses member management to a buyer', function (): void {
        $scenario = workspaceScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
        $project = Project::factory()->forClient($client)->create();
        assign($scenario, $project, $buyer);

        $this->actingAs($buyer)
            ->postJson("/api/projects/{$project->getKey()}/members", [
                'user_id' => $buyer->getKey(),
                'role' => SystemRole::MediaBuyer->value,
            ])
            ->assertForbidden();
    });

    it('lists the people on a project', function (): void {
        $scenario = workspaceScenario();
        $creative = $scenario->addMember(SystemRole::Creative);
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
        $project = Project::factory()->forClient($client)->create();
        assign($scenario, $project, $creative, SystemRole::Creative);

        $this->actingAs($scenario->owner)
            ->getJson("/api/projects/{$project->getKey()}/members")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', (string) $creative->getKey())
            ->assertJsonPath('data.0.role_slug', SystemRole::Creative->value);
    });
});
