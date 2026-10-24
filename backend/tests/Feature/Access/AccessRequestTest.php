<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Asking for an account, and being answered
|--------------------------------------------------------------------------
|
| What is asserted: a request is kept only where this installation takes them; a stranger learns nothing from the answer;
| one agency never reads another's; people are given work and the response time is judged; approving creates nobody, and an
| account exists only once an invitation is accepted — and is only then a first sign-in recorded.
|
*/

function takingRequests(WorkspaceScenario $scenario): void
{
    config(['digitalsmart.access_requests.workspace_slug' => $scenario->workspace->getAttribute('slug')]);
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function requestBody(array $overrides = []): array
{
    return [
        'kind' => 'account_access',
        'name' => 'Noura Al-Harbi',
        'email' => 'Noura@Example.test',
        'company' => 'Alhambra',
        'phone' => '0555001122',
        'need' => 'agency_clients',
        'message' => 'We run eight accounts.',
        'preferred_contact' => 'whatsapp',
        'locale' => 'ar',
        ...$overrides,
    ];
}

/**
 * A JSON list as a typed collection, so the assertions below say what they read.
 *
 * @return Collection<int, array<string, mixed>>
 */
function listOf(mixed $json): Collection
{
    /** @var list<array<string, mixed>> $list */
    $list = is_array($json) ? array_values($json) : [];

    return new Collection($list);
}

/**
 * @param  Collection<int, array<string, mixed>>  $rows
 * @return array<string, mixed>
 */
function rowWhere(Collection $rows, string $key, string $value): array
{
    $row = $rows->firstWhere($key, $value);

    assert($row !== null, "no row with {$key} = {$value}");

    return $row;
}

function firstRequestId(WorkspaceScenario $scenario): string
{
    return (string) test()->actingAs($scenario->owner)->getJson('/api/access-requests')->json('data.0.id');
}

it('says whether the form should be offered', function (): void {
    $this->getJson('/api/public/access-requests')->assertOk()->assertJsonPath('data.open', false);

    takingRequests(WorkspaceScenario::create());

    $this->getJson('/api/public/access-requests')->assertOk()->assertJsonPath('data.open', true);
});

it('keeps a request, of any kind, for the workspace that takes them', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);

    foreach (['account_access', 'general_enquiry', 'demo_request', 'partnership', 'other'] as $kind) {
        $this->postJson('/api/public/access-requests', requestBody(['kind' => $kind, 'email' => $kind.'@example.test']))
            ->assertAccepted()->assertJsonPath('data.received', true);
    }

    $rows = listOf($this->actingAs($scenario->owner)->getJson('/api/access-requests')->assertOk()->json('data'));

    expect($rows)->toHaveCount(5)
        ->and($rows->pluck('kind')->sort()->values()->all())->toBe(['account_access', 'demo_request', 'general_enquiry', 'other', 'partnership'])
        ->and($rows->every(fn (array $r): bool => $r['status'] === 'new'))->toBeTrue();

    $account = rowWhere($rows, 'kind', 'account_access');
    $enquiry = rowWhere($rows, 'kind', 'general_enquiry');

    expect($account['need'])->toBe('agency_clients')
        ->and($account['preferred_contact'])->toBe('whatsapp')
        /* An enquiry has no "need": that question is only asked of somebody asking for an account. */
        ->and($enquiry['need'])->toBeNull();
});

it('answers every submission the same, so a stranger cannot learn who has applied', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);

    $first = $this->postJson('/api/public/access-requests', requestBody())->assertAccepted()->json();
    $repeat = $this->postJson('/api/public/access-requests', requestBody())->assertAccepted()->json();
    $honeypot = $this->postJson('/api/public/access-requests', requestBody(['email' => 'bot@example.test', 'website' => 'http://spam.test']))
        ->assertAccepted()->json();

    config(['digitalsmart.access_requests.workspace_slug' => null]);
    $closed = $this->postJson('/api/public/access-requests', requestBody(['email' => 'late@example.test']))->assertAccepted()->json();

    expect($repeat)->toBe($first)->and($honeypot)->toBe($first)->and($closed)->toBe($first)
        /* The repeat is kept and linked; the honeypot and the closed installation keep nothing. */
        ->and(DB::table('access_requests')->count())->toBe(2);
});

it('links a repeat from the same address or number as a possible duplicate and deletes nothing', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);

    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted();
    $this->postJson('/api/public/access-requests', requestBody(['email' => 'someone.else@example.test', 'phone' => '+966555001122']))->assertAccepted();
    $this->postJson('/api/public/access-requests', requestBody(['email' => 'stranger@example.test', 'phone' => '0500000000']))->assertAccepted();

    $rows = listOf($this->actingAs($scenario->owner)->getJson('/api/access-requests')->json('data'));
    $original = rowWhere($rows, 'email', 'noura@example.test');
    $byPhone = rowWhere($rows, 'email', 'someone.else@example.test');
    $other = rowWhere($rows, 'email', 'stranger@example.test');

    expect($rows)->toHaveCount(3)
        ->and($original['possible_duplicate'])->toBeFalse()
        ->and($byPhone['possible_duplicate'])->toBeTrue()
        ->and($byPhone['possible_duplicate_of'])->toBe($original['id'])
        ->and($other['possible_duplicate'])->toBeFalse();

    $detail = $this->actingAs($scenario->owner)->getJson('/api/access-requests/'.$original['id'])->assertOk()->json('data');

    expect(listOf($detail['linked'])->pluck('id')->all())->toBe([$byPhone['id']]);
});

it('refuses a request that is not one, and a body that is too large', function (): void {
    takingRequests(WorkspaceScenario::create());

    $this->postJson('/api/public/access-requests', requestBody(['email' => 'not-an-address']))->assertUnprocessable();
    $this->postJson('/api/public/access-requests', requestBody(['kind' => 'sell_me_something']))->assertUnprocessable();
    $this->postJson('/api/public/access-requests', requestBody(['preferred_contact' => 'carrier_pigeon']))->assertUnprocessable();
    $this->postJson('/api/public/access-requests', requestBody(['name' => '']))->assertUnprocessable();
    $this->postJson('/api/public/access-requests', requestBody(['message' => str_repeat('x', 20000)]))->assertStatus(413);
});

it('shows requests only to people who can add people, and only in their own workspace', function (): void {
    $agency = WorkspaceScenario::create();
    $other = WorkspaceScenario::create('Another agency');
    takingRequests($agency);

    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted();
    $id = firstRequestId($agency);

    $buyer = $agency->addMember(SystemRole::MediaBuyer);

    $this->actingAs($buyer)->getJson('/api/access-requests')->assertForbidden();
    $this->actingAs($buyer)->getJson("/api/access-requests/{$id}")->assertForbidden();
    $this->actingAs($other->owner)->getJson('/api/access-requests')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($other->owner)->getJson("/api/access-requests/{$id}")->assertNotFound();

    foreach (['assign' => ['owner_id' => null], 'approve' => [], 'reject' => ['reason' => 'No'], 'status' => ['status' => 'closed'], 'notes' => ['body' => 'x'], 'contact' => ['action' => 'contacted']] as $action => $body) {
        $this->actingAs($other->owner)->postJson("/api/access-requests/{$id}/{$action}", $body)->assertNotFound();
    }
});

it('gives a request to somebody who can answer requests, and refuses anybody else', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);
    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted();
    $id = firstRequestId($scenario);

    $admin = $scenario->addMember(SystemRole::Admin);
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);

    $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$id}/assign", ['owner_id' => (string) $admin->getKey()])
        ->assertOk()->assertJsonPath('data.owner_id', (string) $admin->getKey());
    $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$id}/assign", ['owner_id' => (string) $buyer->getKey()])
        ->assertUnprocessable()->assertJsonPath('code', 'access_request_owner_ineligible');
    $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$id}/assign", ['owner_id' => null])
        ->assertOk()->assertJsonPath('data.owner_id', null);
});

it('hands new requests to the workspace default owner when one is set', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);
    $admin = $scenario->addMember(SystemRole::Admin);

    $this->actingAs($scenario->owner)->putJson('/api/access-requests/settings', ['response_minutes' => 30, 'default_owner_id' => (string) $admin->getKey()])
        ->assertOk()->assertJsonPath('data.response_minutes', 30);

    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted();

    $this->actingAs($scenario->owner)->getJson('/api/access-requests')
        ->assertJsonPath('data.0.owner_id', (string) $admin->getKey())
        ->assertJsonPath('meta.settings.response_minutes', 30);

    $buyer = $scenario->addMember(SystemRole::MediaBuyer);
    $this->actingAs($scenario->owner)->putJson('/api/access-requests/settings', ['response_minutes' => 30, 'default_owner_id' => (string) $buyer->getKey()])
        ->assertUnprocessable();
});

it('records contact by hand, moves a new request only when somebody got through, and never claims delivery', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);
    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted();
    $id = firstRequestId($scenario);
    $as = fn () => $this->actingAs($scenario->owner);

    $as()->postJson("/api/access-requests/{$id}/contact", ['action' => 'email_sent', 'note' => 'Sent from my own mail'])
        ->assertOk()->assertJsonPath('data.status', 'new')->assertJsonPath('data.first_contacted_at', null);

    expect($as()->getJson("/api/access-requests/{$id}")->json('data.first_attempt_at'))->not->toBeNull();

    $as()->postJson("/api/access-requests/{$id}/contact", ['action' => 'call_attempted'])->assertOk()->assertJsonPath('data.status', 'new');
    $as()->postJson("/api/access-requests/{$id}/contact", ['action' => 'whatsapp_contacted'])
        ->assertOk()->assertJsonPath('data.status', 'contacted');

    $detail = $as()->getJson("/api/access-requests/{$id}")->assertOk();

    expect($detail->json('data.first_contacted_at'))->not->toBeNull()
        ->and(listOf($detail->json('data.events'))->pluck('type')->all())->toBe(['created', 'email_sent', 'call_attempted', 'whatsapp_contacted'])
        /* No credentials exist, and the product says so rather than pretending a message went. */
        ->and($detail->json('meta.delivery.email.state'))->toBe('awaiting_credentials')
        ->and($detail->json('meta.delivery.whatsapp.state'))->toBe('awaiting_credentials');

    $as()->postJson("/api/access-requests/{$id}/contact", ['action' => 'sent_by_robot'])->assertUnprocessable();
});

it('keeps notes, a follow-up and the status moves on the timeline, and refuses an impossible move', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);
    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted();
    $id = firstRequestId($scenario);
    $as = fn () => $this->actingAs($scenario->owner);
    $when = now()->addDays(2)->startOfHour()->toIso8601String();

    $as()->postJson("/api/access-requests/{$id}/notes", ['body' => 'Asked for a call on Sunday'])->assertOk();
    $as()->postJson("/api/access-requests/{$id}/follow-up", ['at' => $when])->assertOk()->assertJsonPath('data.next_follow_up_at', fn ($v): bool => $v !== null);
    $as()->postJson("/api/access-requests/{$id}/status", ['status' => 'waiting_for_reply'])->assertOk()->assertJsonPath('data.status', 'waiting_for_reply');
    $as()->postJson("/api/access-requests/{$id}/status", ['status' => 'qualified'])->assertOk();
    $as()->postJson("/api/access-requests/{$id}/status", ['status' => 'qualified'])->assertUnprocessable()->assertJsonPath('code', 'access_request_cannot_move');
    /* Deciding has its own use case, and a status route cannot be a way round it. */
    $as()->postJson("/api/access-requests/{$id}/status", ['status' => 'approved'])->assertUnprocessable();

    $types = listOf($as()->getJson("/api/access-requests/{$id}")->json('data.events'))->pluck('type')->all();

    expect($types)->toBe(['created', 'note', 'follow_up_scheduled', 'status_changed', 'status_changed']);
});

it('judges the response time by the lead rule: within, approaching, overdue, and tried is not reached', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);
    $as = fn () => $this->actingAs($scenario->owner);
    $as()->putJson('/api/access-requests/settings', ['response_minutes' => 60])->assertOk();

    foreach (['within', 'approaching', 'overdue', 'tried'] as $name) {
        $this->postJson('/api/public/access-requests', requestBody(['email' => $name.'@example.test', 'phone' => '']))->assertAccepted();
    }

    $age = ['within' => 5, 'approaching' => 50, 'overdue' => 120, 'tried' => 120];

    foreach ($age as $name => $minutes) {
        DB::table('access_requests')->where('email', $name.'@example.test')->update(['created_at' => now()->subMinutes($minutes)]);
    }

    $tried = (string) DB::table('access_requests')->where('email', 'tried@example.test')->value('id');
    $as()->postJson("/api/access-requests/{$tried}/contact", ['action' => 'call_attempted'])->assertOk();
    DB::table('access_requests')->where('id', $tried)->update(['first_attempt_at' => now()->subMinutes(10)]);

    $rows = listOf($as()->getJson('/api/access-requests')->json('data'))->keyBy(fn (array $r): string => explode('@', (string) $r['email'])[0])->all();

    expect($rows['within']['sla']['state'])->toBe('within')
        ->and($rows['approaching']['sla']['state'])->toBe('approaching')
        ->and($rows['overdue']['sla']['state'])->toBe('overdue')
        /* An attempt is not contact: it is judged on its own and is never "contacted". */
        ->and($rows['tried']['sla']['state'])->toBe('attempted')
        ->and($rows['tried']['sla']['met'])->toBeFalse();
});

it('approves an account request without creating anybody, and refuses to approve an enquiry', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);
    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted();
    $this->postJson('/api/public/access-requests', requestBody(['kind' => 'general_enquiry', 'email' => 'ask@example.test', 'phone' => '']))->assertAccepted();
    $rows = listOf($this->actingAs($scenario->owner)->getJson('/api/access-requests')->json('data'));
    $account = (string) rowWhere($rows, 'kind', 'account_access')['id'];
    $enquiry = (string) rowWhere($rows, 'kind', 'general_enquiry')['id'];
    $users = DB::table('users')->count();
    $members = DB::table('workspace_members')->count();

    $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$account}/approve", ['note' => 'Known agency'])
        ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.reviewed_by_name', $scenario->owner->getAttribute('name'));
    $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$enquiry}/approve")
        ->assertUnprocessable()->assertJsonPath('code', 'access_request_not_account');

    expect(DB::table('users')->count())->toBe($users)
        ->and(DB::table('workspace_members')->count())->toBe($members)
        ->and(DB::table('audit_logs')->where('action', 'access.request_approved')->count())->toBe(1);
});

it('rejects only with an internal reason, and keeps that reason and the optional message off the public side', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);
    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted();
    $id = firstRequestId($scenario);

    $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$id}/reject", ['reason' => ''])->assertUnprocessable();
    $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$id}/reject", ['reason' => 'Competitor of a client', 'message' => 'We cannot offer an account now.'])
        ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.review_note', 'Competitor of a client')
        ->assertJsonPath('data.client_message', 'We cannot offer an account now.');

    /* Nothing public reads a request back, so neither can be seen from outside. */
    $public = $this->postJson('/api/public/access-requests', requestBody())->assertAccepted()->getContent();

    expect($public)->not->toContain('Competitor');
    expect($public)->not->toContain('cannot offer');

    $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$id}/contact", ['action' => 'contacted'])->assertUnprocessable();
    $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$id}/approve")->assertUnprocessable();
});

it('invites only after approval, creates the account only when the invitation is accepted, and records the first sign-in', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create();
    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted();
    $id = firstRequestId($scenario);
    $as = fn () => $this->actingAs($scenario->owner);

    $as()->postJson("/api/access-requests/{$id}/invitation", ['role' => 'media-buyer'])->assertUnprocessable()->assertJsonPath('code', 'access_request_not_approved');
    $as()->postJson("/api/access-requests/{$id}/approve")->assertOk();
    $as()->postJson("/api/access-requests/{$id}/invitation", ['role' => 'owner'])->assertUnprocessable()->assertJsonPath('code', 'access_invitation_role');
    $as()->postJson("/api/access-requests/{$id}/invitation", ['role' => 'media-buyer', 'project_ids' => [(string) $project->getKey()]])
        ->assertCreated()->assertJsonPath('data.delivery.email', 'awaiting_credentials');

    $path = (string) $as()->postJson("/api/access-requests/{$id}/invitation", ['role' => 'media-buyer', 'project_ids' => [(string) $project->getKey()]])->json('data.path');
    $token = basename($path);

    expect(DB::table('users')->where('email', 'noura@example.test')->exists())->toBeFalse()
        /* Only a hash is kept. */
        ->and(DB::table('access_invitations')->where('token_hash', $token)->exists())->toBeFalse()
        ->and(DB::table('access_invitations')->where('token_hash', hash('sha256', $token))->exists())->toBeTrue()
        /* The first link was replaced by the second. */
        ->and(DB::table('access_invitations')->whereNotNull('revoked_at')->count())->toBe(1);

    $detail = $as()->getJson("/api/access-requests/{$id}")->json('data');

    expect($detail['invited_at'])->not->toBeNull()
        ->and($detail['invitation_shared_at'])->toBeNull()
        ->and($detail['invitation_opened_at'])->toBeNull()
        ->and($detail['accepted_at'])->toBeNull();

    $as()->postJson("/api/access-requests/{$id}/invitation/shared")->assertOk()->assertJsonPath('data.invitation_shared_at', fn ($v): bool => $v !== null);

    /* Opening is evidence that the page was loaded with the token. */
    $this->getJson("/api/public/invitations/{$token}")->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.needs_password', true);
    expect($as()->getJson("/api/access-requests/{$id}")->json('data.invitation_opened_at'))->not->toBeNull();

    $this->postJson("/api/public/invitations/{$token}/accept", ['password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable();
    expect(DB::table('users')->where('email', 'noura@example.test')->exists())->toBeFalse();

    $this->postJson("/api/public/invitations/{$token}/accept", ['password' => 'a-long-chosen-passphrase', 'password_confirmation' => 'a-long-chosen-passphrase'])
        ->assertOk()->assertJsonPath('data.accepted', true);

    $user = DB::table('users')->where('email', 'noura@example.test')->first();

    assert($user !== null);

    expect($user->email)->toBe('noura@example.test')
        ->and((bool) $user->must_change_password)->toBeFalse()
        ->and(DB::table('workspace_members')->where('user_id', $user->id)->where('workspace_id', $scenario->workspace->getKey())->exists())->toBeTrue()
        ->and(DB::table('project_members')->where('user_id', $user->id)->where('project_id', $project->getKey())->exists())->toBeTrue();

    $after = $as()->getJson("/api/access-requests/{$id}")->json('data');

    expect($after['accepted_at'])->not->toBeNull()->and($after['account_created'])->toBeTrue()->and($after['first_login_at'])->toBeNull();

    /* A used link is the same as no link. */
    $this->getJson("/api/public/invitations/{$token}")->assertOk()->assertJsonPath('data.valid', false);
    $this->postJson("/api/public/invitations/{$token}/accept", ['password' => 'another-long-passphrase', 'password_confirmation' => 'another-long-passphrase'])->assertUnprocessable();

    $this->postJson('/api/auth/login', ['email' => 'noura@example.test', 'password' => 'a-long-chosen-passphrase'])->assertOk();

    /* Read from the table: the browser session is now the new person's, which is the point of the sign-in above. */
    $firstLogin = DB::table('access_requests')->where('id', $id)->value('first_login_at');
    $types = DB::table('access_request_events')->where('access_request_id', $id)->pluck('type')->all();

    expect($firstLogin)->not->toBeNull()
        ->and($types)->toContain('invitation_created', 'invitation_shared', 'invitation_opened', 'invitation_accepted', 'first_login');

    /* A second sign-in does not move the first, and does not write a second event. */
    $this->postJson('/api/auth/logout');
    $this->postJson('/api/auth/login', ['email' => 'noura@example.test', 'password' => 'a-long-chosen-passphrase'])->assertOk();

    expect(DB::table('access_requests')->where('id', $id)->value('first_login_at'))->toBe($firstLogin)
        ->and(DB::table('access_request_events')->where('access_request_id', $id)->where('type', 'first_login')->count())->toBe(1);
});

it('answers the same for a token that was never issued, expired or revoked', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);
    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted();
    $id = firstRequestId($scenario);
    $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$id}/approve")->assertOk();
    $token = basename((string) $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$id}/invitation", ['role' => 'media-buyer'])->json('data.path'));

    DB::table('access_invitations')->update(['expires_at' => now()->subMinute()]);

    $expired = $this->getJson("/api/public/invitations/{$token}")->assertOk()->json();
    $unknown = $this->getJson('/api/public/invitations/'.str_repeat('a', 96))->assertOk()->json();

    expect($expired)->toBe($unknown)->and($unknown['data'])->toBe(['valid' => false]);

    $this->postJson("/api/public/invitations/{$token}/accept", ['password' => 'a-long-chosen-passphrase', 'password_confirmation' => 'a-long-chosen-passphrase'])->assertUnprocessable();
    $this->getJson('/api/public/invitations/not-a-token')->assertNotFound();

    expect(DB::table('users')->where('email', 'noura@example.test')->exists())->toBeFalse();
});

it('joins an existing account to the workspace without touching its password', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);
    $other = WorkspaceScenario::create('Elsewhere');
    $existing = $other->addMember(SystemRole::MediaBuyer);
    $existing->forceFill(['password' => 'the-password-they-chose'])->save();
    $this->postJson('/api/public/access-requests', requestBody(['email' => (string) $existing->getAttribute('email')]))->assertAccepted();
    $id = firstRequestId($scenario);
    $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$id}/approve")->assertOk();
    $token = basename((string) $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$id}/invitation", ['role' => 'creative'])->json('data.path'));

    $this->getJson("/api/public/invitations/{$token}")->assertJsonPath('data.needs_password', false);
    $this->postJson("/api/public/invitations/{$token}/accept", [])->assertOk();

    expect(DB::table('workspace_members')->where('user_id', $existing->getKey())->where('workspace_id', $scenario->workspace->getKey())->exists())->toBeTrue()
        ->and(DB::table('users')->where('email', (string) $existing->getAttribute('email'))->count())->toBe(1);
    $this->postJson('/api/auth/login', ['email' => (string) $existing->getAttribute('email'), 'password' => 'the-password-they-chose'])->assertOk();
});

it('audits what happened without the person’s details, the note, the reason or the token', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);
    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted();
    $id = firstRequestId($scenario);
    $as = fn () => $this->actingAs($scenario->owner);

    $as()->postJson("/api/access-requests/{$id}/notes", ['body' => 'Very private note'])->assertOk();
    $as()->postJson("/api/access-requests/{$id}/contact", ['action' => 'contacted'])->assertOk();
    $as()->postJson("/api/access-requests/{$id}/approve", ['note' => 'Secret approval note'])->assertOk();
    $token = basename((string) $as()->postJson("/api/access-requests/{$id}/invitation", ['role' => 'media-buyer'])->json('data.path'));
    $this->postJson("/api/public/invitations/{$token}/accept", ['password' => 'a-long-chosen-passphrase', 'password_confirmation' => 'a-long-chosen-passphrase'])->assertOk();

    $actions = DB::table('audit_logs')->where('action', 'like', 'access.%')->pluck('action')->all();

    expect($actions)->toContain('access.request_created', 'access.request_noted', 'access.request_contacted', 'access.request_approved', 'access.invitation_created', 'access.invitation_accepted');

    $dump = json_encode(DB::table('audit_logs')->where('action', 'like', 'access.%')->get()->all(), JSON_THROW_ON_ERROR);

    foreach (['noura', 'Noura', 'Alhambra', '0555001122', 'Very private note', 'Secret approval note', $token, 'a-long-chosen-passphrase'] as $secret) {
        expect($dump)->not->toContain($secret);
    }
});

it('tells the people who answer requests, through the notification centre, and nobody else', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);
    $admin = $scenario->addMember(SystemRole::Admin);
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);

    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted();
    $this->postJson('/api/public/access-requests', requestBody(['kind' => 'general_enquiry', 'email' => 'ask@example.test', 'phone' => '']))->assertAccepted();

    $titles = fn ($user): array => listOf($this->actingAs($user)->getJson('/api/notifications')->json('data.notifications'))->pluck('title')->all();

    expect($titles($admin))->toContain('A request for an account arrived', 'An enquiry arrived')
        ->and($titles($buyer))->toBe([]);

    $dump = json_encode(DB::table('notifications')->get()->all(), JSON_THROW_ON_ERROR);

    expect($dump)->not->toContain('Noura');
    expect($dump)->not->toContain('example.test');
});

it('raises one alert for a request past its response time and one for an invitation nobody accepted', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);
    $admin = $scenario->addMember(SystemRole::Admin);
    $this->actingAs($scenario->owner)->putJson('/api/access-requests/settings', ['response_minutes' => 30])->assertOk();
    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted();
    $id = firstRequestId($scenario);
    DB::table('access_requests')->where('id', $id)->update(['created_at' => now()->subMinutes(25)]);

    $this->artisan('access-requests:alerts')->assertSuccessful();
    $this->artisan('access-requests:alerts')->assertSuccessful();

    $kinds = fn (): array => DB::table('notifications')->where('user_id', $admin->getKey())->pluck('kind')->all();

    expect(collect($kinds())->filter(fn ($k): bool => $k === 'access_request_approaching')->count())->toBe(1);

    DB::table('access_requests')->where('id', $id)->update(['created_at' => now()->subHours(3)]);
    $this->artisan('access-requests:alerts')->assertSuccessful();

    expect(collect($kinds())->filter(fn ($k): bool => $k === 'access_request_overdue')->count())->toBe(1);

    $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$id}/contact", ['action' => 'contacted'])->assertOk();
    $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$id}/approve")->assertOk();
    $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$id}/invitation", ['role' => 'media-buyer'])->assertCreated();
    DB::table('access_requests')->where('id', $id)->update(['invited_at' => now()->subHours(60)]);

    $this->artisan('access-requests:alerts')->assertSuccessful();
    $this->artisan('access-requests:alerts')->assertSuccessful();

    expect(collect($kinds())->filter(fn ($k): bool => $k === 'access_invitation_pending')->count())->toBe(1);
});

it('reports small operational figures, and a rate only over something', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);
    $as = fn () => $this->actingAs($scenario->owner);

    $empty = $as()->getJson('/api/access-requests/figures')->assertOk()->json('data');

    expect($empty['new_requests'])->toBe(0)->and($empty['sla_compliance'])->toBeNull()
        ->and($empty['median_first_response_seconds'])->toBeNull()->and($empty['invitation_acceptance'])->toBeNull();

    $this->postJson('/api/public/access-requests', requestBody(['email' => 'a@example.test', 'phone' => '']))->assertAccepted();
    $this->postJson('/api/public/access-requests', requestBody(['email' => 'b@example.test', 'phone' => '']))->assertAccepted();
    DB::table('access_requests')->update(['created_at' => now()->subMinutes(10)]);
    $ids = DB::table('access_requests')->pluck('id')->all();
    $as()->postJson("/api/access-requests/{$ids[0]}/contact", ['action' => 'contacted'])->assertOk();
    $as()->postJson("/api/access-requests/{$ids[0]}/approve")->assertOk();
    $as()->postJson("/api/access-requests/{$ids[1]}/reject", ['reason' => 'No'])->assertOk();

    $figures = $as()->getJson('/api/access-requests/figures')->assertOk()->json('data');

    expect($figures['new_requests'])->toBe(2)->and($figures['contacted'])->toBe(1)->and($figures['approved'])->toBe(1)
        ->and($figures['rejected'])->toBe(1)->and($figures['median_first_response_seconds'])->toBeInt()
        ->and($figures['sla_compliance'])->toEqual(1);
});
