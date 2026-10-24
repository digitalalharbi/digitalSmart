<?php

declare(strict_types=1);

use App\Application\Saas\Contract\UsageReader;
use App\Domain\Access\SystemRole;
use App\Domain\Saas\Limit;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use App\Infrastructure\Persistence\Eloquent\Model\Workspace;
use App\Infrastructure\Persistence\Eloquent\Model\WorkspaceMember;
use App\Infrastructure\Workspace\WorkspaceProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Plans, features and limits
|--------------------------------------------------------------------------
|
| A permission says what a person may do; an entitlement says what the workspace has. Both are asked, and neither answers for
| the other: an owner holds every permission and is still refused a feature their plan does not include. A limit refuses the
| next one, never the ones already there, and a downgrade removes nothing.
|
*/

/** @return array{owner: User, workspace: Workspace} */
function saasWorkspace(): array
{
    app(WorkspaceProvisioner::class)->syncPermissionCatalogue();
    $owner = User::factory()->create();
    $workspace = app(WorkspaceProvisioner::class)->provision(
        name: 'Plan Test',
        slug: 'plan-test-'.Str::lower(Str::random(6)),
        owner: $owner,
    );

    return ['owner' => $owner, 'workspace' => $workspace];
}

function saasPlan(Workspace $workspace, string $plan): void
{
    test()->artisan('saas:plan', [
        'workspace' => $workspace->getKey(),
        'plan' => $plan,
        '--operator' => 'ops@smartcode.test',
        '--reason' => 'test',
    ])->assertSuccessful();
}

function saasOverride(Workspace $workspace, string $kind, string $key, string $value): void
{
    test()->artisan('saas:override', [
        'workspace' => $workspace->getKey(),
        'kind' => $kind,
        'key' => $key,
        'value' => $value,
        '--operator' => 'ops@smartcode.test',
        '--reason' => 'test',
    ])->assertSuccessful();
}

function saasMember(Workspace $workspace, SystemRole $role): User
{
    $user = User::factory()->create();
    WorkspaceMember::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'role_id' => DB::table('roles')->where('workspace_id', $workspace->getKey())->where('slug', $role->value)->value('id'),
        'status' => 'active',
        'joined_at' => now(),
    ]);

    return $user;
}

it('starts every new workspace on the default plan, with a row that says so', function (): void {
    ['owner' => $owner, 'workspace' => $workspace] = saasWorkspace();

    expect(DB::table('workspace_subscriptions')->where('workspace_id', $workspace->getKey())->value('assigned_by'))->toBe('provisioning');

    $this->actingAs($owner)->getJson('/api/workspace/plan')
        ->assertOk()
        ->assertJsonPath('data.plan.code', 'agency')
        ->assertJsonFragment(['key' => 'clients', 'allowed' => null, 'used' => 0, 'source' => 'plan', 'monthly' => false, 'resets_at' => null, 'over' => false, 'refuses' => true]);
});

it('shows the plan only to the people who administer the workspace', function (): void {
    ['workspace' => $workspace] = saasWorkspace();

    $this->actingAs(saasMember($workspace, SystemRole::MediaBuyer))->getJson('/api/workspace/plan')->assertForbidden();
});

it('refuses a feature the plan lacks to an owner who holds every permission, until an override grants it', function (): void {
    ['owner' => $owner, 'workspace' => $workspace] = saasWorkspace();
    saasPlan($workspace, 'starter');

    $this->actingAs($owner)->getJson('/api/automation/rules')
        ->assertForbidden()
        ->assertJsonPath('code', 'feature_not_in_plan')
        ->assertJsonPath('feature', 'automation');

    saasOverride($workspace, 'feature', 'automation', 'on');
    $this->actingAs($owner)->getJson('/api/automation/rules')->assertOk();

    saasOverride($workspace, 'feature', 'automation', 'inherit');
    $this->actingAs($owner)->getJson('/api/automation/rules')->assertForbidden();
});

it('keeps a permission refusal a permission refusal on a plan that has the feature', function (): void {
    ['workspace' => $workspace] = saasWorkspace();

    $this->actingAs(saasMember($workspace, SystemRole::ClientViewer))->getJson('/api/automation/rules')
        ->assertForbidden()
        ->assertJsonMissingPath('feature');
});

it('refuses the client past the limit with the figures, and keeps every client already there', function (): void {
    ['owner' => $owner, 'workspace' => $workspace] = saasWorkspace();
    Client::factory()->count(2)->create(['workspace_id' => $workspace->getKey()]);
    saasOverride($workspace, 'limit', 'clients', '2');

    $this->actingAs($owner)->postJson('/api/clients', ['name' => 'One too many'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'quota_exceeded')
        ->assertJsonPath('limit', 'clients')
        ->assertJsonPath('used', 2)
        ->assertJsonPath('allowed', 2);

    expect(Client::query()->where('workspace_id', $workspace->getKey())->count())->toBe(2);

    saasOverride($workspace, 'limit', 'clients', 'unlimited');
    $this->actingAs($owner)->postJson('/api/clients', ['name' => 'Now there is room'])->assertCreated();
});

it('removes nothing on a downgrade and says the workspace is over its plan', function (): void {
    ['owner' => $owner, 'workspace' => $workspace] = saasWorkspace();
    Client::factory()->count(6)->create(['workspace_id' => $workspace->getKey()]);

    saasPlan($workspace, 'starter');

    expect(Client::query()->where('workspace_id', $workspace->getKey())->count())->toBe(6);
    $this->actingAs($owner)->getJson('/api/workspace/plan')
        ->assertJsonPath('data.plan.code', 'starter')
        ->assertJsonFragment(['key' => 'clients', 'allowed' => 5, 'used' => 6, 'source' => 'plan', 'monthly' => false, 'resets_at' => null, 'over' => true, 'refuses' => true]);
    $this->actingAs($owner)->postJson('/api/clients', ['name' => 'Seventh'])->assertStatus(409);
});

it('counts agency people and client people against their own seats', function (): void {
    ['owner' => $owner, 'workspace' => $workspace] = saasWorkspace();
    saasOverride($workspace, 'limit', 'internal_users', '1');

    $this->actingAs($owner)->postJson('/api/members', ['name' => 'Second Buyer', 'email' => 'buyer2@example.test', 'role' => 'media-buyer'])
        ->assertStatus(409)
        ->assertJsonPath('limit', 'internal_users');

    // The client seat is separate, so the agency being full does not stop a client being added.
    $this->actingAs($owner)->postJson('/api/members', ['name' => 'Client Person', 'email' => 'client@example.test', 'role' => 'client-viewer'])
        ->assertSuccessful();

    expect(DB::table('workspace_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
        ->where('u.email', 'client@example.test')->value('m.seat_type'))->toBe('client');
});

it('counts a metered event once, however often it is reported', function (): void {
    ['workspace' => $workspace] = saasWorkspace();
    $usage = app(UsageReader::class);
    $now = new DateTimeImmutable;

    expect($usage->record($workspace->getKey(), Limit::AiRequestsMonthly, 'request-1', $now))->toBeTrue()
        ->and($usage->record($workspace->getKey(), Limit::AiRequestsMonthly, 'request-1', $now))->toBeFalse()
        ->and($usage->record($workspace->getKey(), Limit::AiRequestsMonthly, 'request-2', $now))->toBeTrue()
        ->and($usage->used($workspace->getKey(), Limit::AiRequestsMonthly, $now))->toBe(2);
});

it('records every plan change and override with the operator and the reason, and refuses one without them', function (): void {
    ['workspace' => $workspace] = saasWorkspace();

    $this->artisan('saas:plan', ['workspace' => $workspace->getKey(), 'plan' => 'growth'])->assertFailed();
    $this->artisan('saas:plan', ['workspace' => $workspace->getKey(), 'plan' => 'platinum', '--operator' => 'ops', '--reason' => 'x'])->assertFailed();
    $this->artisan('saas:override', ['workspace' => $workspace->getKey(), 'kind' => 'feature', 'key' => 'overview', 'value' => 'off', '--operator' => 'ops', '--reason' => 'x'])->assertFailed();
    $this->artisan('saas:override', ['workspace' => $workspace->getKey(), 'kind' => 'limit', 'key' => 'clients', 'value' => 'lots', '--operator' => 'ops', '--reason' => 'x'])->assertFailed();

    saasPlan($workspace, 'growth');
    saasOverride($workspace, 'limit', 'clients', '40');

    $entries = DB::table('audit_logs')->where('workspace_id', $workspace->getKey())
        ->whereIn('action', ['workspace.plan_changed', 'workspace.entitlement_overridden'])->orderBy('created_at')->get();

    expect($entries)->toHaveCount(2);

    $changes = array_map(static fn (object $row): array => (array) json_decode((string) $row->changes, true), $entries->all());

    expect($changes[0])->toMatchArray(['plan' => ['from' => 'agency', 'to' => 'growth'], 'operator' => 'ops@smartcode.test', 'reason' => 'test'])
        ->and($changes[1])->toMatchArray(['kind' => 'limit', 'key' => 'clients', 'from' => '25', 'to' => '40']);
    expect(DB::table('workspace_entitlement_overrides')->where('workspace_id', $workspace->getKey())->count())->toBe(1);
});
