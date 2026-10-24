<?php

declare(strict_types=1);

use App\Application\Shared\Contract\ActorContextBinder;
use App\Application\Workspace\Command\InviteMember;
use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Domain\Commerce\AttributionEvidence;
use App\Domain\Creative\AssetKind;
use App\Domain\Creative\CreativeState;
use App\Domain\Identity\Email;
use App\Domain\Identity\UserId;
use App\Domain\Leads\LeadSourceKind;
use App\Domain\Leads\LeadStage;
use App\Domain\Operations\ClientRequestKind;
use App\Domain\Operations\ClientRequestState;
use App\Domain\Operations\StatedUrgency;
use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskState;
use App\Domain\Workspace\MembershipStatus;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use App\Infrastructure\Persistence\Eloquent\Model\WorkspaceMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WorkspaceScenario;

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

    /*
     * `200` with a body rather than `204`: the response says what was released from their name. Four tables
     * carry an assignment, and work left in a removed person's name is a board showing items owned by somebody
     * who cannot open them.
     */
    $this->actingAs($scenario->owner)
        ->deleteJson("/api/members/{$buyer->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.released.total', 0);

    /* Revoked, not deleted: the row is the record of who was here, and it grants nothing. */
    expect(WorkspaceMember::query()->where('user_id', $buyer->getKey())->value('status'))->toBe('revoked')
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

    $this->actingAs($scenario->owner)->deleteJson("/api/members/{$buyer->getKey()}")->assertOk();

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

/**
 * Give somebody one open item of each assignable kind, written straight to the tables.
 *
 * Four tables carry an assignment and a schema sweep is what found the fourth. The rows are written without
 * going through the use cases on purpose: this is a test about what *removal* does to rows that already
 * exist, and the rules those use cases enforce about who may be assigned are tested where they live.
 *
 * @return array{task: string, lead: string, request: string, brief: string}
 */
function openWorkFor(WorkspaceScenario $scenario, User $person): array
{
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $ids = [
        'task' => (string) Str::uuid7(),
        'lead' => (string) Str::uuid7(),
        'request' => (string) Str::uuid7(),
        'brief' => (string) Str::uuid7(),
    ];
    $common = [
        'workspace_id' => $scenario->workspace->getKey(),
        'client_id' => $client->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('tasks')->insert([...$common,
        'id' => $ids['task'],
        'title' => 'Open work in their name',
        'state' => TaskState::Todo->value,
        'priority' => TaskPriority::Normal->value,
        'assignee_id' => $person->getKey(),
        'created_by' => $scenario->owner->getKey(),
        'state_changed_at' => now(),
    ]);

    DB::table('leads')->insert([...$common,
        'id' => $ids['lead'],
        'source_kind' => LeadSourceKind::FormSubmission->value,
        'stage' => LeadStage::New->value,
        'attribution_evidence' => AttributionEvidence::None->value,
        'owner_id' => $person->getKey(),
        'received_at' => now(),
    ]);

    DB::table('client_requests')->insert([...$common,
        'id' => $ids['request'],
        'kind' => ClientRequestKind::Question->value,
        'title' => 'A client waiting on somebody',
        'detail' => 'Their question.',
        'state' => ClientRequestState::Submitted->value,
        'stated_urgency' => StatedUrgency::WheneverYouCan->value,
        'submitted_by' => $scenario->owner->getKey(),
        'owner_id' => $person->getKey(),
        'state_changed_at' => now(),
    ]);

    DB::table('creative_requests')->insert([...$common,
        'id' => $ids['brief'],
        'title' => 'A brief in their name',
        'state' => CreativeState::Draft->value,
        'kind' => AssetKind::Image->value,
        'owner_id' => $person->getKey(),
        'created_by' => $scenario->owner->getKey(),
        'state_changed_at' => now(),
    ]);

    return $ids;
}

describe('removal and the work they were holding', function (): void {
    /*
     * The point of the slice. Work left in a removed person's name is a board showing items owned by
     * somebody who cannot open them, a workload chart counting a person who has gone, and a client request
     * waiting on a reply from nobody.
     */
    it('releases their open work and says how much', function (): void {
        $scenario = workspaceScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);
        $work = openWorkFor($scenario, $buyer);

        $released = $this->actingAs($scenario->owner)
            ->deleteJson("/api/members/{$buyer->getKey()}")
            ->assertOk()
            ->json('data.released');

        expect($released)->toBe([
            'tasks' => 1,
            'leads' => 1,
            'client_requests' => 1,
            'creative_requests' => 1,
            'total' => 4,
        ]);

        /* And the rows say so, which is what makes the boards true rather than merely quieter. */
        expect(DB::table('tasks')->where('id', $work['task'])->value('assignee_id'))->toBeNull()
            ->and(DB::table('leads')->where('id', $work['lead'])->value('owner_id'))->toBeNull()
            ->and(DB::table('client_requests')->where('id', $work['request'])->value('owner_id'))->toBeNull()
            ->and(DB::table('creative_requests')->where('id', $work['brief'])->value('owner_id'))->toBeNull();
    });

    /*
     * Finished work keeps their name. A task somebody completed is the record of who completed it, and
     * rewriting that to say nobody did would be falsifying history to tidy a column.
     */
    it('leaves finished work attributed to them', function (): void {
        $scenario = workspaceScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);
        $work = openWorkFor($scenario, $buyer);

        DB::table('tasks')->where('id', $work['task'])
            ->update(['state' => TaskState::Done->value, 'completed_at' => now()]);

        $released = $this->actingAs($scenario->owner)
            ->deleteJson("/api/members/{$buyer->getKey()}")
            ->assertOk()
            ->json('data.released');

        expect($released['tasks'])->toBe(0)
            ->and($released['total'])->toBe(3)
            ->and(DB::table('tasks')->where('id', $work['task'])->value('assignee_id'))
            ->toBe((string) $buyer->getKey());
    });

    /*
     * Its own audit action. A removal used to be recorded as a suspension, which answers "what happened to
     * their twelve tasks" wrongly: one is somebody expected back whose work waits, the other is somebody
     * gone whose work was handed back.
     */
    it('records the released counts under its own audit action', function (): void {
        $scenario = workspaceScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);
        openWorkFor($scenario, $buyer);

        $this->actingAs($scenario->owner)->deleteJson("/api/members/{$buyer->getKey()}")->assertOk();

        $entry = AuditLog::query()
            ->where('action', AuditAction::WorkspaceMemberRemoved->value)
            ->sole();

        expect($entry->getAttribute('changes')['released']['total'])->toBe(4);
    });

    /*
     * Suspension releases nothing, deliberately: a suspended member is expected back, their work waits for
     * them, and the status beside their name in the directory is what says they are away.
     */
    it('leaves the work alone when somebody is only suspended', function (): void {
        $scenario = workspaceScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);
        $work = openWorkFor($scenario, $buyer);

        $this->actingAs($scenario->owner)
            ->patchJson("/api/members/{$buyer->getKey()}/status", ['status' => MembershipStatus::Suspended->value])
            /* `204`, where removal answers `200` with a body: there is nothing to report because nothing moved. */
            ->assertNoContent();

        expect(DB::table('tasks')->where('id', $work['task'])->value('assignee_id'))
            ->toBe((string) $buyer->getKey());
    });
});

describe('what they are holding, before the decision', function (): void {
    it('reports what somebody still holds, per kind', function (): void {
        $scenario = workspaceScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);
        openWorkFor($scenario, $buyer);

        $this->actingAs($scenario->owner)
            ->getJson("/api/members/{$buyer->getKey()}/held-work")
            ->assertOk()
            ->assertExactJson(['data' => [
                'tasks' => 1,
                'leads' => 1,
                'client_requests' => 1,
                'creative_requests' => 1,
                'total' => 4,
            ]]);
    });

    it('reports an honest zero for somebody holding nothing', function (): void {
        $scenario = workspaceScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);

        $this->actingAs($scenario->owner)
            ->getJson("/api/members/{$buyer->getKey()}/held-work")
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    });

    /* Not a confident zero about a stranger: a question about somebody who is not here has no answer. */
    it('reports somebody in another workspace as not found', function (): void {
        $mine = workspaceScenario('Mine');
        $theirs = workspaceScenario('Theirs');
        $stranger = $theirs->addMember(SystemRole::MediaBuyer);

        $this->actingAs($mine->owner)
            ->getJson("/api/members/{$stranger->getKey()}/held-work")
            ->assertNotFound();
    });

    /* The figure says how loaded a named colleague is. Same permission as the removal it informs. */
    it('is not a project manager’s to read', function (): void {
        $scenario = workspaceScenario();
        $manager = $scenario->addMember(SystemRole::ProjectManager);
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);

        $this->actingAs($manager)
            ->getJson("/api/members/{$buyer->getKey()}/held-work")
            ->assertForbidden();
    });
});
