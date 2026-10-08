<?php

declare(strict_types=1);

use App\Application\Shared\Contract\ActorContextBinder;
use App\Application\Workspace\Command\InviteMember;
use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Domain\Identity\Email;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\MembershipStatus;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\WorkspaceMember;
use Symfony\Component\HttpFoundation\Response;

it('requires authentication', function (): void {
    $this->getJson('/api/members')->assertUnauthorized();
});

it('lists the people in the active workspace only', function (): void {
    $mine = workspaceScenario('Mine');
    $mine->addMember(SystemRole::MediaBuyer);
    $theirs = workspaceScenario('Theirs');
    $theirs->addMember(SystemRole::Creative);

    $response = $this->actingAs($mine->owner)->getJson('/api/members');

    // Owner + buyer, never the other workspace's people.
    $response->assertOk()->assertJsonPath('meta.total', 2);
});

it('hides the member directory from a client viewer', function (): void {
    $scenario = workspaceScenario();
    $viewer = $scenario->addMember(SystemRole::ClientViewer);

    $this->actingAs($viewer)->getJson('/api/members')->assertForbidden();
});

it('changes a member role and records who did it', function (): void {
    $scenario = workspaceScenario();
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);

    $this->actingAs($scenario->owner)
        ->patchJson("/api/members/{$buyer->getKey()}/role", ['role' => SystemRole::ProjectManager->value])
        ->assertNoContent();

    expect(AuditLog::query()
        ->where('action', AuditAction::WorkspaceMemberRoleChanged->value)
        ->where('actor_id', $scenario->owner->getKey())
        ->exists())->toBeTrue();

    // The new role takes effect immediately.
    $this->actingAs($buyer)->getJson('/api/clients')->assertOk();
    $this->actingAs($buyer)->postJson('/api/clients', ['name' => 'Now allowed'])->assertCreated();
});

it('refuses to demote the last owner', function (): void {
    $scenario = workspaceScenario();

    $this->actingAs($scenario->owner)
        ->patchJson("/api/members/{$scenario->owner->getKey()}/role", ['role' => SystemRole::Creative->value])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonPath('code', 'last_owner_protected');
});

it('allows demoting an owner once another owner exists', function (): void {
    $scenario = workspaceScenario();
    $second = $scenario->addMember(SystemRole::Owner);

    $this->actingAs($second)
        ->patchJson("/api/members/{$scenario->owner->getKey()}/role", ['role' => SystemRole::Creative->value])
        ->assertNoContent();
});

it('suspends a member and strips their access immediately', function (): void {
    $scenario = workspaceScenario();
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);

    $this->actingAs($scenario->owner)
        ->patchJson("/api/members/{$buyer->getKey()}/status", ['status' => MembershipStatus::Suspended->value])
        ->assertNoContent();

    // Suspended: the role still says buyer, but it grants nothing.
    $this->actingAs($buyer)->getJson('/api/clients')->assertForbidden();
});

it('reinstates a suspended member', function (): void {
    $scenario = workspaceScenario();
    $buyer = $scenario->addMember(SystemRole::MediaBuyer, MembershipStatus::Suspended);

    $this->actingAs($scenario->owner)
        ->patchJson("/api/members/{$buyer->getKey()}/status", ['status' => MembershipStatus::Active->value])
        ->assertNoContent();

    $this->actingAs($buyer)->getJson('/api/clients')->assertOk();
});

it('refuses to suspend the last owner', function (): void {
    $scenario = workspaceScenario();

    $this->actingAs($scenario->owner)
        ->patchJson("/api/members/{$scenario->owner->getKey()}/status", ['status' => 'suspended'])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonPath('code', 'last_owner_protected');
});

it('removes a member but keeps their account and audit trail', function (): void {
    $scenario = workspaceScenario();
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);

    $this->actingAs($scenario->owner)
        ->deleteJson("/api/members/{$buyer->getKey()}")
        ->assertNoContent();

    expect(WorkspaceMember::query()->where('user_id', $buyer->getKey())->exists())->toBeFalse()
        ->and($buyer->fresh())->not->toBeNull();
});

it('refuses member administration to a project manager', function (): void {
    $scenario = workspaceScenario();
    $manager = $scenario->addMember(SystemRole::ProjectManager);
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);

    $this->actingAs($manager)
        ->patchJson("/api/members/{$buyer->getKey()}/role", ['role' => SystemRole::Creative->value])
        ->assertForbidden();
});

it('reports a member of another workspace as not found', function (): void {
    $mine = workspaceScenario('Mine');
    $outsider = workspaceScenario('Theirs')->owner;

    $this->actingAs($mine->owner)
        ->patchJson("/api/members/{$outsider->getKey()}/role", ['role' => SystemRole::Creative->value])
        ->assertNotFound();
});

it('denies a user whose workspace membership has been removed entirely', function (): void {
    $scenario = workspaceScenario();
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);

    $this->actingAs($scenario->owner)->deleteJson("/api/members/{$buyer->getKey()}")->assertNoContent();

    // Their session may still exist, but it now carries no workspace at all.
    $response = $this->actingAs($buyer)->getJson('/api/clients');

    $response->assertForbidden();
    expect($response->json('message'))->toContain('active membership of any workspace');
});

/*
|--------------------------------------------------------------------------
| Adding a colleague
|--------------------------------------------------------------------------
|
| Three pieces of vocabulary described this and nothing performed it: `Permission::UsersInvite`, granted to
| owners and administrators and checked nowhere; `AuditAction::WorkspaceMemberInvited`, declared with nothing
| recording it; and `MembershipStatus::Invited`, which nothing ever set. Roles could be changed, memberships
| suspended and people removed — but the workspace was closed to anybody new.
|
*/

describe('adding a colleague', function (): void {
    it('creates the account and returns a password to read out, once', function (): void {
        $scenario = workspaceScenario();

        $data = $this->actingAs($scenario->owner)
            ->postJson('/api/members', [
                'name' => 'Noura Al-Shehri',
                'email' => 'noura@example.com',
                'role' => SystemRole::MediaBuyer->value,
            ])
            ->assertCreated()
            ->json('data');

        expect($data['email'])->toBe('noura@example.com')
            ->and($data['role'])->toBe('media-buyer')
            ->and($data['is_existing_account'])->toBeFalse()
            /* Long, because nobody has to remember it: it is read out once and replaced. */
            ->and(mb_strlen((string) $data['one_time_password']))->toBeGreaterThanOrEqual(32);

        /* And it works, which is the only test of "they can sign in" that cannot pass by accident. */
        $this->post('/api/auth/logout');
        $this->postJson('/api/auth/login', [
            'email' => 'noura@example.com',
            'password' => $data['one_time_password'],
        ])->assertOk();
    });

    /*
     * The password is in the response and nowhere else. Never stored in plaintext, never logged, never audited —
     * if the administrator loses it there is nowhere to look it up, which is the property that makes a read-out
     * password tolerable at all.
     */
    it('never writes the password into the audit trail', function (): void {
        $scenario = workspaceScenario();

        $data = $this->actingAs($scenario->owner)
            ->postJson('/api/members', [
                'name' => 'Noura Al-Shehri',
                'email' => 'noura@example.com',
                'role' => SystemRole::MediaBuyer->value,
            ])
            ->assertCreated()
            ->json('data');

        $entry = AuditLog::query()
            ->where('action', AuditAction::WorkspaceMemberInvited->value)
            ->firstOrFail();

        expect(json_encode($entry->getAttributes()))
            ->not->toContain((string) $data['one_time_password']);
    });

    /*
     * The whole reason a read-out password is acceptable: it stops working the moment it is used. Until then
     * every request except reading the session, changing the password and signing out is refused.
     */
    it('refuses everything else until the member chooses their own password', function (): void {
        $scenario = workspaceScenario();

        /*
         * Added through the use case with an actor bound the way the request middleware binds one, rather than
         * over HTTP as the owner.
         *
         * `actingAs()` installs a user resolver for the whole test, so a session opened afterwards by signing in
         * over HTTP is still answered as the first user — and the session's stored password hash belongs to them.
         * That is a fact about the test harness rather than about the product, and the only way to assert what a
         * new member's own browser experiences is not to be signed in as somebody else first.
         */
        app(ActorContextBinder::class)->bind(
            new UserId((string) $scenario->owner->getKey()),
            new WorkspaceId((string) $scenario->workspace->getKey()),
        );

        $invited = app(InviteMember::class)(
            'Noura Al-Shehri',
            new Email('noura@example.com'),
            SystemRole::MediaBuyer->value,
        );

        $data = ['one_time_password' => $invited->oneTimePassword];

        $this->postJson('/api/auth/login', [
            'email' => 'noura@example.com',
            'password' => $data['one_time_password'],
        ])->assertOk();

        /* The session reads, because a client cannot render even the dialog without knowing who it is. */
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.must_choose_password', true);

        /*
         * Everything else is refused with a code the interface acts on. Asserted on a surface this role may
         * actually reach — a media buyer has no `clients.view`, and an ordinary permission denial is also a 403,
         * so picking the wrong endpoint would have made this test pass for the wrong reason.
         */
        $this->getJson('/api/leads')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');

        $this->postJson('/api/auth/password', [
            'current_password' => $data['one_time_password'],
            'password' => 'a-passphrase-of-my-own',
            'password_confirmation' => 'a-passphrase-of-my-own',
        ])->assertNoContent();

        /*
         * And now the product opens, and the flag is gone.
         *
         * `forgetGuards()` first, because the test harness keeps one application instance for the whole test and
         * the auth guard caches the model it resolved: without this, the next request is answered from the user
         * as it was *before* the password changed. Every real request resolves the user afresh, so this is the
         * line that makes the test behave like a second request rather than a continuation of the first.
         */
        app('auth')->forgetGuards();

        $this->getJson('/api/leads')->assertOk();
        $this->getJson('/api/auth/me')->assertJsonPath('data.must_choose_password', false);
    });

    /*
     * One account per person across the installation. Somebody who already works on this deployment is joined to
     * this workspace rather than duplicated — and no password is returned, because none was created: they have
     * one they chose, and resetting it would lock them out of the other workspace.
     */
    it('joins an existing account rather than duplicating the person', function (): void {
        $theirs = workspaceScenario('Theirs');
        $existing = $theirs->addMember(SystemRole::Creative);
        $mine = workspaceScenario('Mine');

        $data = $this->actingAs($mine->owner)
            ->postJson('/api/members', [
                'name' => 'Ignored, because the account already has a name',
                'email' => $existing->getAttribute('email'),
                'role' => SystemRole::MediaBuyer->value,
            ])
            ->assertCreated()
            ->json('data');

        expect($data['is_existing_account'])->toBeTrue()
            ->and($data['one_time_password'])->toBe('')
            ->and($data['user_id'])->toBe((string) $existing->getKey());

        expect(WorkspaceMember::query()->where('user_id', $existing->getKey())->count())->toBe(2);
    });

    it('refuses somebody who is already in this workspace', function (): void {
        $scenario = workspaceScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);

        $this->actingAs($scenario->owner)
            ->postJson('/api/members', [
                'name' => 'Already here',
                'email' => $buyer->getAttribute('email'),
                'role' => SystemRole::Creative->value,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'member_not_added');
    });

    /*
     * An owner can do everything, including removing other owners, so creating one is a decision for somebody
     * already in that position — `ChangeMemberRole` is where it happens, with the last-owner protection around
     * it.
     */
    it('will not hand out the owner role from an add form', function (): void {
        $scenario = workspaceScenario();

        $this->actingAs($scenario->owner)
            ->postJson('/api/members', [
                'name' => 'A second owner',
                'email' => 'owner2@example.com',
                'role' => SystemRole::Owner->value,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'member_not_added');
    });

    it('refuses a role that does not exist, on the field somebody typed it into', function (): void {
        $scenario = workspaceScenario();

        $this->actingAs($scenario->owner)
            ->postJson('/api/members', [
                'name' => 'Noura Al-Shehri',
                'email' => 'noura@example.com',
                'role' => 'chief-vibes-officer',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    });

    it('is not a project manager’s to do', function (): void {
        $scenario = workspaceScenario();
        $manager = $scenario->addMember(SystemRole::ProjectManager);

        $this->actingAs($manager)
            ->postJson('/api/members', [
                'name' => 'Noura Al-Shehri',
                'email' => 'noura@example.com',
                'role' => SystemRole::MediaBuyer->value,
            ])
            ->assertForbidden();
    });
});
