<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Lead;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use App\Infrastructure\Persistence\Eloquent\Model\Store;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Every agency-wide surface, from the point of view of a client
|--------------------------------------------------------------------------
|
| Two clients in one workspace. One of them is "ZETA-SECRET", with a project, a budget, a lead, a store, a task
| and an internal tag. A user of the *other* client opens every global surface the agency has, and the marker
| must appear in none of them — the same test for each, so a new surface cannot be added without being added
| here, and a surface that is open to a client fails by name.
|
| The control assertions matter as much: the owner must find the marker on the same surfaces, otherwise the
| sweep would pass because it saw nothing at all.
*/

const MARKER = 'ZETA-SECRET';

/** @return array{s: WorkspaceScenario, viewer: User, own: Project, secret: Project, secretClient: Client} */
function isolationWorld(): array
{
    $s = WorkspaceScenario::create();
    $ws = $s->workspace->getKey();

    $ownClient = Client::factory()->create(['workspace_id' => $ws, 'name' => 'Visible Client']);
    $secretClient = Client::factory()->create(['workspace_id' => $ws, 'name' => MARKER.' Holdings']);
    $own = Project::factory()->forClient($ownClient)->create(['name' => 'Visible Project']);
    $secret = Project::factory()->forClient($secretClient)->create(['name' => MARKER.' Launch']);

    $viewer = $s->addMember(SystemRole::ClientViewer);
    ProjectMember::query()->create(['project_id' => $own->getKey(), 'user_id' => $viewer->getKey(), 'role_id' => $s->role(SystemRole::ClientViewer)->getKey()]);

    $owner = $s->owner;
    $budget = test()->actingAs($owner)->postJson('/api/budgets', [
        'client_id' => (string) $secretClient->getKey(), 'project_id' => (string) $secret->getKey(), 'name' => MARKER.' Budget',
        'period_start' => now()->subDays(5)->format('Y-m-d'), 'period_end' => now()->addDays(20)->format('Y-m-d'),
        'amount' => '5000.00', 'currency' => 'SAR',
    ])->json('data.id');
    test()->actingAs($owner)->postJson("/api/budgets/{$budget}/approve");

    Lead::query()->create([
        'workspace_id' => $ws, 'client_id' => $secretClient->getKey(), 'project_id' => $secret->getKey(),
        'source_kind' => 'form_submission', 'name' => MARKER.' Prospect', 'stage' => 'new', 'received_at' => now()->subDay(),
    ]);
    Store::query()->create([
        'workspace_id' => $ws, 'client_id' => $secretClient->getKey(), 'provider' => 'salla', 'external_id' => 'iso-1',
        'name' => MARKER.' Store', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh',
    ]);
    test()->actingAs($owner)->postJson('/api/tasks', [
        'client_id' => (string) $secretClient->getKey(), 'project_id' => (string) $secret->getKey(),
        'title' => MARKER.' Task', 'priority' => 'normal',
    ]);
    $tag = test()->actingAs($owner)->postJson('/api/tags', ['name' => MARKER.' Tag'])->json('data.id');
    test()->actingAs($owner)->putJson("/api/tags/{$tag}/entities/project/{$secret->getKey()}");
    DB::table('audit_logs')->insert([
        'id' => (string) Illuminate\Support\Str::uuid(), 'workspace_id' => $ws, 'actor_id' => $owner->getKey(),
        'action' => 'project.status_changed', 'auditable_type' => 'project', 'auditable_id' => $secret->getKey(),
        'summary' => MARKER.' moved to active', 'changes' => '{}', 'context' => '{}', 'created_at' => now(),
    ]);

    return ['s' => $s, 'viewer' => $viewer, 'own' => $own, 'secret' => $secret, 'secretClient' => $secretClient];
}

/** @return list<string> */
function globalSurfaces(Project $secret, Client $secretClient): array
{
    return [
        '/api/portfolio',
        '/api/portfolio/clients/'.$secretClient->getKey(),
        '/api/benchmarks',
        '/api/search?q=ZETA',
        '/api/search?q=SECRET',
        '/api/budgets/control',
        '/api/budgets/alerts',
        '/api/campaigns/control',
        '/api/campaigns/ownership',
        '/api/attention',
        '/api/home',
        '/api/digests',
        '/api/tags',
        '/api/shortcuts',
        '/api/integrations/connections',
        '/api/integrations/account-queue',
        '/api/integrations/operations',
        '/api/measurement/agency-health',
        '/api/leads',
        '/api/leads/centre',
        '/api/commerce/rollup?revenue_source=salla',
        '/api/commerce/stores',
        '/api/activity',
        '/api/exports',
        '/api/exports/schedules',
        '/api/views/campaign_control',
        '/api/views/budget_control',
        '/api/views/leads',
        '/api/projects/'.$secret->getKey().'/readiness',
        '/api/clients/'.$secretClient->getKey().'/offboarding',
        '/api/tasks/board',
        '/api/approvals/queue',
        '/api/creative/board',
        '/api/client-requests/board',
        '/api/clients',
        '/api/projects',
    ];
}

it('shows a client user none of another client’s data on any global surface', function (): void {
    ['viewer' => $viewer, 'secret' => $secret, 'secretClient' => $secretClient] = isolationWorld();

    $leaks = [];

    foreach (globalSurfaces($secret, $secretClient) as $url) {
        $response = $this->actingAs($viewer)->getJson($url);

        if (! in_array($response->status(), [200, 403, 404], true)) {
            $leaks[] = sprintf('%s answered %d', $url, $response->status());

            continue;
        }

        if (str_contains(strtolower((string) $response->getContent()), strtolower(MARKER))) {
            $leaks[] = sprintf('%s leaked the other client’s data', $url);
        }
    }

    expect($leaks)->toBe([]);
});

it('does find the same data as the owner, so the sweep is not passing on an empty world', function (): void {
    ['s' => $s, 'secret' => $secret, 'secretClient' => $secretClient] = isolationWorld();

    $seen = 0;

    foreach (['/api/portfolio', '/api/search?q=ZETA', '/api/budgets/control', '/api/leads', '/api/commerce/rollup?revenue_source=salla', '/api/tags', '/api/activity'] as $url) {
        $content = (string) $this->actingAs($s->owner)->getJson($url)->assertOk()->getContent();

        if (str_contains(strtolower($content), strtolower(MARKER))) {
            $seen++;
        }
    }

    expect($seen)->toBeGreaterThanOrEqual(6);
    expect($secret->getKey())->not->toBeNull();
    expect($secretClient->getKey())->not->toBeNull();
});

it('cannot be talked into the other client by naming it', function (): void {
    ['viewer' => $viewer, 'secretClient' => $secretClient, 'secret' => $secret] = isolationWorld();
    $c = (string) $secretClient->getKey();
    $p = (string) $secret->getKey();

    foreach ([
        "/api/leads?client_id={$c}",
        "/api/leads/centre?client_id={$c}&project_id={$p}",
        "/api/commerce/rollup?revenue_source=salla&client_id={$c}",
        "/api/campaigns/control?client_id={$c}&project_id={$p}",
        "/api/budgets/control?client_id={$c}&project_id={$p}",
        '/api/attention?audience=my_projects',
    ] as $url) {
        $content = strtolower((string) $this->actingAs($viewer)->getJson($url)->getContent());

        expect(str_contains($content, strtolower(MARKER)))->toBeFalse($url);
    }
});
