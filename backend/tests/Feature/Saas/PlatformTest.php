<?php

declare(strict_types=1);

use App\Application\Agents\Contract\AgentRunLog;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use App\Infrastructure\Persistence\Eloquent\Model\Workspace;
use App\Infrastructure\Workspace\WorkspaceProvisioner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The platform console and the subscription lifecycle
|--------------------------------------------------------------------------
|
| An operator runs tenants from outside them: a flag set only on the server, never a workspace role, and no membership of the
| workspaces they operate. Suspending a workspace stops its writes and its scheduled work and touches none of its data.
|
*/

/** A fresh guard per person: the request guard remembers whoever it resolved first. */
function platformAs(User $user): mixed
{
    Auth::forgetGuards();
    test()->flushSession();

    return test()->actingAs($user);
}

/** @return array{owner: User, workspace: Workspace, operator: User} */
function platformScene(): array
{
    app(WorkspaceProvisioner::class)->syncPermissionCatalogue();
    $owner = User::factory()->create(['password' => 'tenant-owner-password']);
    $workspace = app(WorkspaceProvisioner::class)->provision(name: 'Tenant One', slug: 'tenant-one-'.Str::lower(Str::random(5)), owner: $owner);
    Client::factory()->create(['workspace_id' => $workspace->getKey(), 'name' => 'Secret Client Name']);

    $operator = User::factory()->create(['email' => 'ops-'.Str::lower(Str::random(5)).'@smartcode.test']);
    test()->artisan('platform:operator', ['email' => $operator->getAttribute('email'), '--by' => 'root', '--reason' => 'on call'])->assertSuccessful();

    return ['owner' => $owner, 'workspace' => $workspace, 'operator' => $operator];
}

it('lets only an operator into the console, and an operator gets nothing inside a workspace', function (): void {
    ['owner' => $owner, 'workspace' => $workspace, 'operator' => $operator] = platformScene();

    platformAs($owner)->getJson('/api/platform/workspaces')->assertForbidden();

    $response = platformAs($operator)->getJson('/api/platform/workspaces?search=tenant one')->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $workspace->getKey());

    expect($row)->toMatchArray(['plan' => 'agency', 'status' => 'active', 'owners' => 1, 'clients' => 1, 'provisioning' => 'complete'])
        ->and($row['seats'])->toBe(['internal' => 1, 'client' => 0])
        ->and($response->getContent())->not->toContain('Secret Client Name');

    // Operating the platform is not membership: the operator's own session reaches no tenant data.
    platformAs($operator)->getJson('/api/clients')->assertForbidden();
    platformAs($operator)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.is_platform_operator', true)->assertJsonPath('data.active_workspace', null);
});

it('grants and withdraws operator access only from the console, and records both against no workspace', function (): void {
    ['operator' => $operator] = platformScene();

    $this->artisan('platform:operator', ['email' => $operator->getAttribute('email'), '--revoke' => true, '--by' => 'root'])->assertFailed();
    $this->artisan('platform:operator', ['email' => 'nobody@example.test', '--by' => 'root', '--reason' => 'x'])->assertFailed();
    $this->artisan('platform:operator', ['email' => $operator->getAttribute('email'), '--revoke' => true, '--by' => 'root', '--reason' => 'left'])->assertSuccessful();

    platformAs($operator)->getJson('/api/platform/workspaces')->assertForbidden();
    expect(DB::table('audit_logs')->where('action', 'platform.operator_changed')->whereNull('workspace_id')->count())->toBe(2);
});

it('suspends a workspace: everything readable, nothing writable, no scheduled work, no data lost — and resumes it', function (): void {
    ['owner' => $owner, 'workspace' => $workspace, 'operator' => $operator] = platformScene();
    $id = $workspace->getKey();

    platformAs($operator)->postJson("/api/platform/workspaces/{$id}/status", ['status' => 'suspended'])->assertUnprocessable();
    platformAs($operator)->postJson("/api/platform/workspaces/{$id}/status", ['status' => 'suspended', 'reason' => 'Invoice 114 unpaid'])->assertNoContent();

    platformAs($owner)->getJson('/api/clients')->assertOk()->assertJsonCount(1, 'data');
    platformAs($owner)->postJson('/api/clients', ['name' => 'New'])
        ->assertStatus(423)
        ->assertJsonPath('code', 'workspace_not_writable')
        ->assertJsonPath('status', 'suspended')
        ->assertJsonPath('reason', 'Invoice 114 unpaid');
    platformAs($owner)->getJson('/api/auth/me')->assertJsonPath('data.subscription.status', 'suspended');
    expect(app(AgentRunLog::class)->workspaces(null))->not->toContain($id);

    platformAs($operator)->postJson("/api/platform/workspaces/{$id}/status", ['status' => 'active', 'reason' => 'Paid'])->assertNoContent();
    platformAs($owner)->postJson('/api/clients', ['name' => 'New'])->assertCreated();
    expect(app(AgentRunLog::class)->workspaces(null))->toContain($id)
        ->and(Client::query()->where('workspace_id', $id)->count())->toBe(2);

    $changes = DB::table('audit_logs')->where('workspace_id', $id)->where('action', 'workspace.subscription_changed')->orderBy('created_at')->pluck('changes')
        ->map(static fn (string $c): array => (array) json_decode($c, true))->all();
    expect($changes[0])->toMatchArray(['status' => ['from' => 'active', 'to' => 'suspended'], 'operator' => $operator->getAttribute('email'), 'reason' => 'Invoice 114 unpaid']);
});

it('creates a workspace with its roles, plan, trial and owner, and shows a one-time password only for a new account', function (): void {
    ['owner' => $owner, 'operator' => $operator] = platformScene();

    $payload = ['name' => 'Brand Two', 'slug' => 'brand-two', 'type' => 'brand', 'locale' => 'ar', 'timezone' => 'Asia/Riyadh',
        'currency' => 'sar', 'plan' => 'growth', 'status' => 'trial', 'trial_days' => 30, 'owner_name' => 'New Owner', 'owner_email' => 'new.owner@example.test'];

    platformAs($owner)->postJson('/api/platform/workspaces', $payload)->assertForbidden();

    $created = platformAs($operator)->postJson('/api/platform/workspaces', $payload)->assertCreated();
    $id = (string) $created->json('data.workspace_id');

    expect($created->json('data.one_time_password'))->toBeString()->toHaveLength(32);
    $detail = platformAs($operator)->getJson("/api/platform/workspaces/{$id}")->assertOk();
    $detail->assertJsonPath('data.type', 'brand')->assertJsonPath('data.plan', 'growth')->assertJsonPath('data.status', 'trial')
        ->assertJsonPath('data.owners', 1)->assertJsonPath('data.provisioning', 'complete')
        ->assertJsonPath('data.history.0.action', 'workspace.created');
    expect(DB::table('workspaces')->where('id', $id)->value('default_currency'))->toBe('SAR')
        ->and(DB::table('users')->where('email', 'new.owner@example.test')->value('must_change_password'))->toBeTrue();

    platformAs($operator)->postJson('/api/platform/workspaces', $payload)->assertUnprocessable()->assertJsonPath('code', 'platform_refused');

    // An existing person becomes the owner on their own account, with no password handed to anybody.
    $again = platformAs($operator)->postJson('/api/platform/workspaces', [...$payload, 'slug' => 'brand-three', 'status' => 'active', 'owner_email' => $owner->getAttribute('email')])->assertCreated();
    expect($again->json('data.one_time_password'))->toBeNull();
});

it('changes a plan and an override from the console, named after the operator', function (): void {
    ['workspace' => $workspace, 'operator' => $operator] = platformScene();
    $id = $workspace->getKey();

    platformAs($operator)->postJson("/api/platform/workspaces/{$id}/plan", ['plan' => 'starter', 'reason' => 'Downgrade requested'])->assertNoContent();
    platformAs($operator)->postJson("/api/platform/workspaces/{$id}/overrides", ['kind' => 'feature', 'key' => 'automation', 'value' => 'on', 'reason' => 'Pilot'])
        ->assertOk()->assertJsonPath('data.previous_value', 'off');
    platformAs($operator)->postJson('/api/platform/workspaces/'.Str::uuid()->toString().'/plan', ['plan' => 'starter', 'reason' => 'x'])->assertNotFound();

    $detail = platformAs($operator)->getJson("/api/platform/workspaces/{$id}")->assertOk();
    $detail->assertJsonPath('data.plan', 'starter')->assertJsonPath('data.overrides', 1)->assertJsonPath('data.plan_detail.overrides.0.changed_by', $operator->getAttribute('email'));
    expect(collect($detail->json('data.plan_detail.features'))->firstWhere('key', 'automation')['enabled'])->toBeTrue();
});
