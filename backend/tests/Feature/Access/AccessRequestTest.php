<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Asking for an account
|--------------------------------------------------------------------------
|
| What is asserted: a request is kept only where this installation takes them, a stranger learns nothing from the
| answer, one agency never reads another's prospects, and approving a request creates nobody.
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
        'name' => 'Noura Al-Harbi',
        'email' => 'Noura@Example.test',
        'company' => 'Alhambra',
        'need' => 'agency_clients',
        'message' => 'We run eight accounts.',
        'locale' => 'ar',
        ...$overrides,
    ];
}

it('says whether the form should be offered', function (): void {
    $this->getJson('/api/public/access-requests')->assertOk()->assertJsonPath('data.open', false);

    takingRequests(WorkspaceScenario::create());

    $this->getJson('/api/public/access-requests')->assertOk()->assertJsonPath('data.open', true);
});

it('keeps a request for the workspace that takes them, in a form an administrator can read', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);

    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted()->assertJsonPath('data.received', true);

    $row = $this->actingAs($scenario->owner)->getJson('/api/access-requests')->assertOk()->json('data.0');

    expect($row['email'])->toBe('noura@example.test')
        ->and($row['status'])->toBe('pending')
        ->and($row['need'])->toBe('agency_clients');
});

it('answers the same when nothing was kept, so a stranger cannot learn who has applied', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);

    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted();
    $repeat = $this->postJson('/api/public/access-requests', requestBody())->assertAccepted()->json();
    $honeypot = $this->postJson('/api/public/access-requests', requestBody(['email' => 'bot@example.test', 'website' => 'http://spam.test']))
        ->assertAccepted()->json();

    config(['digitalsmart.access_requests.workspace_slug' => null]);
    $closed = $this->postJson('/api/public/access-requests', requestBody(['email' => 'late@example.test']))->assertAccepted()->json();

    expect($repeat)->toBe($honeypot)->and($closed)->toBe($honeypot)
        ->and(DB::table('access_requests')->count())->toBe(1);
});

it('refuses a request that is not one', function (): void {
    takingRequests(WorkspaceScenario::create());

    $this->postJson('/api/public/access-requests', requestBody(['email' => 'not-an-address']))->assertUnprocessable();
    $this->postJson('/api/public/access-requests', requestBody(['need' => 'something_else']))->assertUnprocessable();
    $this->postJson('/api/public/access-requests', requestBody(['name' => '']))->assertUnprocessable();
});

it('shows requests only to people who can add people, and only in their own workspace', function (): void {
    $agency = WorkspaceScenario::create();
    $other = WorkspaceScenario::create('Another agency');
    takingRequests($agency);

    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted();

    $buyer = $agency->addMember(SystemRole::MediaBuyer);

    $this->actingAs($buyer)->getJson('/api/access-requests')->assertForbidden();
    $this->actingAs($other->owner)->getJson('/api/access-requests')->assertOk()->assertJsonCount(0, 'data');
});

it('records a decision, refuses to undo it, and creates nobody', function (): void {
    $scenario = WorkspaceScenario::create();
    takingRequests($scenario);
    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted();

    $id = (string) $this->actingAs($scenario->owner)->getJson('/api/access-requests')->json('data.0.id');
    $users = DB::table('users')->count();

    $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$id}/review", ['status' => 'contacted'])
        ->assertOk()->assertJsonPath('data.status', 'contacted');
    $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$id}/review", ['status' => 'approved', 'note' => 'Call booked'])
        ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.review_note', 'Call booked');
    $this->actingAs($scenario->owner)->postJson("/api/access-requests/{$id}/review", ['status' => 'declined'])
        ->assertUnprocessable()->assertJsonPath('code', 'access_request_cannot_move');

    expect(DB::table('users')->count())->toBe($users)
        ->and(DB::table('audit_logs')->where('action', 'access.request_reviewed')->count())->toBe(2);
});

it('does not let another workspace decide on a request', function (): void {
    $agency = WorkspaceScenario::create();
    $other = WorkspaceScenario::create('Another agency');
    takingRequests($agency);
    $this->postJson('/api/public/access-requests', requestBody())->assertAccepted();

    $id = (string) $this->actingAs($agency->owner)->getJson('/api/access-requests')->json('data.0.id');

    $this->actingAs($other->owner)->postJson("/api/access-requests/{$id}/review", ['status' => 'approved'])->assertNotFound();
});
