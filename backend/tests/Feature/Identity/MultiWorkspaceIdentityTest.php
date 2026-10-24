<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Workspace\WorkspaceType;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use App\Infrastructure\Persistence\Eloquent\Model\Workspace;
use App\Infrastructure\Persistence\Eloquent\Model\WorkspaceMember;
use App\Infrastructure\Workspace\WorkspaceProvisioner;
use App\Interface\Http\Middleware\BindActorContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| One person, several workspaces
|--------------------------------------------------------------------------
|
| One identity — one `users` row, one password — belongs to an agency, a brand and a freelancer workspace, with a different
| role in each. Signing in once, they work in one workspace at a time: its permissions, its clients and its connections, and
| nothing of the others. Leaving is revoking, not deleting, and coming back reuses the same membership.
|
*/

function workspaceOfType(string $name, WorkspaceType $type, User $owner): Workspace
{
    return app(WorkspaceProvisioner::class)->provision(
        name: $name,
        slug: Str::slug($name).'-'.Str::lower(Str::random(5)),
        owner: $owner,
        type: $type,
    );
}

function joinAs(Workspace $workspace, User $user, SystemRole $role, int $daysAgo): void
{
    WorkspaceMember::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'role_id' => DB::table('roles')->where('workspace_id', $workspace->getKey())->where('slug', $role->value)->value('id'),
        'status' => 'active',
        'joined_at' => now()->subDays($daysAgo),
    ]);
}

/** @return array{person: User, agency: Workspace, brand: Workspace, freelance: Workspace} */
function threeWorkspaces(): array
{
    app(WorkspaceProvisioner::class)->syncPermissionCatalogue();

    $person = User::factory()->create(['password' => 'one-password-everywhere']);
    $agency = workspaceOfType('Agency HQ', WorkspaceType::Agency, User::factory()->create());
    $brand = workspaceOfType('Brand In-house', WorkspaceType::Brand, User::factory()->create());
    $freelance = workspaceOfType('My Studio', WorkspaceType::Freelancer, $person);

    /* The agency is where they started, so it is where a fresh sign-in lands. */
    joinAs($agency, $person, SystemRole::Admin, 30);
    joinAs($brand, $person, SystemRole::MediaBuyer, 10);
    DB::table('workspace_members')->where('workspace_id', $freelance->getKey())->where('user_id', $person->getKey())->update(['joined_at' => now()->subDays(5)]);

    Client::factory()->create(['workspace_id' => $agency->getKey(), 'name' => 'Agency Client']);
    Client::factory()->create(['workspace_id' => $brand->getKey(), 'name' => 'Brand Itself']);
    Client::factory()->create(['workspace_id' => $freelance->getKey(), 'name' => 'Studio Client']);

    return ['person' => $person, 'agency' => $agency, 'brand' => $brand, 'freelance' => $freelance];
}

function signInAs(User $person): void
{
    Auth::forgetGuards();
    test()->postJson('/api/auth/login', ['email' => $person->getAttribute('email'), 'password' => 'one-password-everywhere'])->assertOk();
}

/**
 * @return Collection<int, array<string, mixed>>
 */
function rowsOf(mixed $json): Collection
{
    /** @var list<array<string, mixed>> $rows */
    $rows = is_array($json) ? array_values($json) : [];

    return new Collection($rows);
}

/** @return list<string> */
function clientNames(): array
{
    return array_column(test()->getJson('/api/clients')->assertOk()->json('data'), 'name');
}

it('signs one identity into one workspace at a time, with that workspace’s role, clients and type', function (): void {
    $w = threeWorkspaces();
    signInAs($w['person']);

    $me = test()->getJson('/api/auth/me')->assertOk()->json('data');

    expect(rowsOf($me['workspaces'])->pluck('name')->sort()->values()->all())->toBe(['Agency HQ', 'Brand In-house', 'My Studio'])
        ->and(rowsOf($me['workspaces'])->firstWhere('name', 'Brand In-house')['type'] ?? null)->toBe('brand')
        ->and($me['active_workspace']['name'])->toBe('Agency HQ')
        ->and(clientNames())->toBe(['Agency Client']);

    test()->postJson('/api/workspaces/'.$w['brand']->getKey().'/activate')->assertOk()
        ->assertJsonPath('data.active_workspace.name', 'Brand In-house')
        ->assertJsonPath('data.active_workspace.role', 'media-buyer');

    /* The brand role decides, not the agency one: an agency administrator is a media buyer here, who may not add people. */
    expect(test()->getJson('/api/auth/me')->json('data.permissions'))->not->toContain('users.invite');
    test()->postJson('/api/members', ['name' => 'X', 'email' => 'x@example.test', 'role' => 'creative'])->assertForbidden();
    expect(clientNames())->not->toContain('Agency Client');

    test()->postJson('/api/workspaces/'.$w['freelance']->getKey().'/activate')->assertOk()
        ->assertJsonPath('data.active_workspace.role', 'owner');

    expect(clientNames())->toBe(['Studio Client']);
});

it('keeps each workspace’s provider connections to itself', function (): void {
    $w = threeWorkspaces();
    signInAs($w['person']);

    test()->postJson('/api/workspaces/'.$w['freelance']->getKey().'/activate')->assertOk();
    test()->putJson('/api/integrations/providers/meta', ['credentials' => ['client_id' => 'sandbox', 'client_secret' => 'sandbox']])->assertCreated();

    test()->postJson('/api/workspaces/'.$w['agency']->getKey().'/activate')->assertOk();
    $meta = rowsOf(test()->getJson('/api/integrations/providers')->assertOk()->json('data'))->firstWhere('slug', 'meta');

    expect($meta['connection'] ?? null)->toBeNull()
        ->and(DB::table('provider_connections')->where('workspace_id', $w['freelance']->getKey())->count())->toBe(1)
        ->and(DB::table('provider_connections')->where('workspace_id', $w['agency']->getKey())->count())->toBe(0);
});

it('refuses a workspace the person does not belong to, or has been suspended from, as not found', function (): void {
    $w = threeWorkspaces();
    $stranger = workspaceOfType('Somebody Else', WorkspaceType::Agency, User::factory()->create());
    signInAs($w['person']);

    test()->postJson('/api/workspaces/'.$stranger->getKey().'/activate')->assertNotFound();

    DB::table('workspace_members')->where('workspace_id', $w['brand']->getKey())->where('user_id', $w['person']->getKey())->update(['status' => 'suspended']);
    test()->postJson('/api/workspaces/'.$w['brand']->getKey().'/activate')->assertNotFound();

    expect(clientNames())->toBe(['Agency Client']);
});

it('records every switch in the workspace entered, naming the one left by id only', function (): void {
    $w = threeWorkspaces();
    signInAs($w['person']);

    test()->postJson('/api/workspaces/'.$w['brand']->getKey().'/activate')->assertOk();

    $entry = DB::table('audit_logs')->where('action', 'workspace.switched')->first();

    assert($entry !== null);

    expect($entry->action)->toBe('workspace.switched')
        ->and($entry->workspace_id)->toBe((string) $w['brand']->getKey())
        ->and(json_decode((string) $entry->changes, true))->toEqualCanonicalizing(['from' => (string) $w['agency']->getKey(), 'to' => (string) $w['brand']->getKey()])
        ->and(DB::table('audit_logs')->where('action', 'workspace.switched')->where('workspace_id', $w['agency']->getKey())->count())->toBe(0);
});

it('revokes rather than deletes, hides the revoked by default, and reactivates the same membership on a new invitation', function (): void {
    $w = threeWorkspaces();
    $owner = User::query()->whereKey(DB::table('workspace_members')->where('workspace_id', $w['brand']->getKey())->where('status', 'active')->where('user_id', '<>', $w['person']->getKey())->value('user_id'))->firstOrFail();
    $row = (string) DB::table('workspace_members')->where('workspace_id', $w['brand']->getKey())->where('user_id', $w['person']->getKey())->value('id');

    test()->actingAs($owner)->deleteJson('/api/members/'.$w['person']->getKey())->assertOk();

    $revoked = DB::table('workspace_members')->where('id', $row)->first();

    assert($revoked !== null);

    expect($revoked->status)->toBe('revoked')
        ->and($revoked->revoked_by)->toBe((string) $owner->getKey())
        ->and($revoked->revoked_at)->not->toBeNull()
        ->and(rowsOf(test()->actingAs($owner)->getJson('/api/members')->json('data'))->pluck('user_id')->all())->not->toContain((string) $w['person']->getKey())
        ->and(rowsOf(test()->actingAs($owner)->getJson('/api/members?status=revoked')->json('data'))->pluck('user_id')->all())->toBe([(string) $w['person']->getKey()]);

    /* Removing twice is not found: there is no membership left to remove. */
    test()->actingAs($owner)->deleteJson('/api/members/'.$w['person']->getKey())->assertNotFound();

    test()->actingAs($owner)->postJson('/api/members', [
        'name' => 'Back again', 'email' => $w['person']->getAttribute('email'), 'role' => 'creative',
    ])->assertCreated()->assertJsonPath('data.one_time_password', '');

    $back = DB::table('workspace_members')->where('workspace_id', $w['brand']->getKey())->where('user_id', $w['person']->getKey())->get();

    $first = $back->first();

    assert($first !== null);

    expect($back)->toHaveCount(1)
        ->and($first->id)->toBe($row)
        ->and($first->status)->toBe('active')
        ->and($first->invited_by)->toBe((string) $owner->getKey())
        ->and($first->revoked_at)->toBeNull();
});

it('never lets a revoked membership sign in to that workspace', function (): void {
    $w = threeWorkspaces();
    DB::table('workspace_members')->where('workspace_id', $w['brand']->getKey())->where('user_id', $w['person']->getKey())
        ->update(['status' => 'revoked', 'revoked_at' => now()]);
    signInAs($w['person']);

    expect(rowsOf(test()->getJson('/api/auth/me')->json('data.workspaces'))->pluck('name')->all())->not->toContain('Brand In-house');
    test()->postJson('/api/workspaces/'.$w['brand']->getKey().'/activate')->assertNotFound();
});

it('keeps the workspace a switch chose even when a request that began earlier writes the old session back', function (): void {
    $w = threeWorkspaces();
    signInAs($w['person']);

    $switch = test()->postJson('/api/workspaces/'.$w['brand']->getKey().'/activate')->assertOk();
    $cookie = null;

    foreach ($switch->headers->getCookies() as $sent) {
        if ($sent->getName() === 'ds_workspace') {
            $cookie = $sent;
        }
    }

    /* Sent by the switch, HTTP-only, and encrypted: the browser holds a value it cannot read or forge. */
    expect($cookie)->not->toBeNull()
        ->and($cookie?->isHttpOnly())->toBeTrue()
        ->and($cookie?->getValue())->not->toBe((string) $w['brand']->getKey());

    /*
     * The race, reproduced at the resolver: a notification poll that started before the switch finishes after it and writes
     * the whole session back with the agency still in it. The cookie — which only sign-in and a switch ever send — decides.
     */
    $request = Request::create('/api/clients', 'GET', cookies: ['ds_workspace' => (string) $w['brand']->getKey()]);
    $request->setLaravelSession(tap(app('session')->driver())->put('active_workspace_id', (string) $w['agency']->getKey()));

    expect(BindActorContext::requested($request))->toBe((string) $w['brand']->getKey());
});

it('ignores a workspace cookie for a workspace the person does not belong to', function (): void {
    $w = threeWorkspaces();
    $stranger = workspaceOfType('Somebody Else', WorkspaceType::Agency, User::factory()->create());
    signInAs($w['person']);

    /* Sent as the browser would: from the first-party origin, so the cookie is decrypted and actually read. */
    test()->withHeader('Referer', 'http://localhost/')
        ->withCookie('ds_workspace', (string) $stranger->getKey())
        ->getJson('/api/auth/me')->assertOk()
        ->assertJsonPath('data.active_workspace.name', 'Agency HQ');

    test()->withHeader('Referer', 'http://localhost/')->withCookie('ds_workspace', 'not-a-uuid')->getJson('/api/auth/me')->assertOk()
        ->assertJsonPath('data.active_workspace.name', 'Agency HQ');
});
