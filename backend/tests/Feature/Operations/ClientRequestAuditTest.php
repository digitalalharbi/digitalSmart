<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| The client-request audit trail
|--------------------------------------------------------------------------
|
| Its own file, because the entries this module writes are the ones somebody reads back in an argument: who
| promised a date, who declined a request, who was handling it, and who could see what was said.
|
| Every assertion here names the **actor, the action, the subject and the workspace**, and finds its entry by the
| content it is asserting rather than by when it was written. An earlier version of the promise test ordered on
| the timestamp, and because both entries landed inside the same second it asserted whichever row Postgres felt
| like returning first — a test that passed for the wrong reason, and would have kept passing with the audit entry
| missing its "from" value entirely.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project, viewer: User, manager: User} */
function auditScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    $viewer = $scenario->addMember(SystemRole::ClientViewer);
    $manager = $scenario->addMember(SystemRole::ProjectManager);

    foreach ([[$viewer, SystemRole::ClientViewer], [$manager, SystemRole::ProjectManager]] as [$user, $role]) {
        ProjectMember::query()->create([
            'project_id' => $project->getKey(),
            'user_id' => $user->getKey(),
            'role_id' => $scenario->role($role)->getKey(),
        ]);
    }

    return [
        'scenario' => $scenario,
        'client' => $client,
        'project' => $project,
        'viewer' => $viewer,
        'manager' => $manager,
    ];
}

/**
 * The single audit entry for one action on one subject, asserted to be single.
 *
 * Finding it by action and subject rather than by order, and failing loudly on more than one, is what makes the
 * assertions below about the entry they mean rather than about whichever row came back.
 *
 * @return array{entry: AuditLog, changes: array<string, mixed>}
 */
function soleEntry(AuditAction $action, string $subjectId): array
{
    $entries = AuditLog::query()
        ->where('action', $action->value)
        ->where('auditable_id', $subjectId)
        ->get();

    expect($entries)->toHaveCount(1);

    $entry = $entries->first();

    expect($entry)->not->toBeNull();

    /** @var AuditLog $entry */
    /** @var array<string, mixed> $changes */
    $changes = $entry->changes;

    return ['entry' => $entry, 'changes' => $changes];
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project, viewer: User, manager: User}  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function auditRequest(array $setup, ?User $as = null, array $overrides = []): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($as ?? $setup['scenario']->owner)
        ->postJson('/api/client-requests', [
            'client_id' => (string) $setup['client']->getKey(),
            'project_id' => (string) $setup['project']->getKey(),
            'kind' => 'creative',
            'title' => 'Three new story creatives for Eid',
            'detail' => 'Same framing as the Ramadan set.',
            ...$overrides,
        ])
        ->assertCreated()
        ->json('data');
}

it('records who raised a request, for which client, and that it came from the client', function (): void {
    $setup = auditScenario();
    $request = auditRequest($setup, $setup['viewer'], ['stated_urgency' => 'this_week']);

    ['entry' => $entry, 'changes' => $changes] = soleEntry(
        AuditAction::ClientRequestSubmitted,
        $request['id'],
    );

    // Actor, action, subject and scope, all four named.
    expect((string) $entry->actor_id)->toBe((string) $setup['viewer']->getKey());
    expect($entry->action)->toBe(AuditAction::ClientRequestSubmitted->value);
    expect($entry->auditable_type)->toBe('client_request');
    expect((string) $entry->auditable_id)->toBe($request['id']);
    expect((string) $entry->workspace_id)->toBe((string) $setup['scenario']->workspace->getKey());
    expect($entry->created_at)->not->toBeNull();

    // And the metadata that makes the entry worth having.
    expect($changes['from_client'])->toBeTrue();
    expect($changes['client_id'])->toBe((string) $setup['client']->getKey());
    expect($changes['kind'])->toBe('creative');
    /*
     * Recorded as the client's own word. Nothing in this product turns it into a priority, and an audit that
     * conflated the two would be evidence for the wrong claim.
     */
    expect($changes['stated_urgency'])->toBe('this_week');
});

it('records a promise against the person who made it, and both sides of a change to it', function (): void {
    $setup = auditScenario();
    $request = auditRequest($setup, $setup['viewer']);
    $manager = $setup['manager'];

    test()->actingAs($manager)->postJson("/api/client-requests/{$request['id']}/state", [
        'state' => 'accepted',
    ])->assertOk();

    $first = now()->addDays(8)->toDateString();
    $second = now()->addDays(12)->toDateString();

    foreach ([$first, $second] as $date) {
        test()->actingAs($manager)
            ->postJson("/api/client-requests/{$request['id']}/promise", ['promised_at' => $date])
            ->assertOk();
    }

    $entries = AuditLog::query()
        ->where('action', AuditAction::ClientRequestPromised->value)
        ->where('auditable_id', $request['id'])
        ->get();

    expect($entries)->toHaveCount(2);

    /*
     * Each entry located by what it says, not by when it was written. Both land inside the same second, so
     * ordering on the timestamp asserts whichever row came back — which is how the earlier version of this test
     * passed while the value it meant to check was null.
     */
    $byTarget = [];

    foreach ($entries as $candidate) {
        /** @var array<string, mixed> $values */
        $values = $candidate->changes;
        $byTarget[(string) ($values['to'] ?? '')] = ['entry' => $candidate, 'changes' => $values];
    }

    expect(array_keys($byTarget))->toContain($first);
    expect(array_keys($byTarget))->toContain($second);

    // The first promise: nothing before it.
    expect($byTarget[$first]['changes']['from'])->toBeNull();

    // The second: the date it replaced, which is the answer to "it was the eighth last week".
    expect($byTarget[$second]['changes']['from'])->toBe($first);

    $moved = $byTarget[$second]['entry'];

    expect((string) $moved->actor_id)->toBe((string) $manager->getKey());
    expect($moved->auditable_type)->toBe('client_request');
    expect((string) $moved->workspace_id)->toBe((string) $setup['scenario']->workspace->getKey());
    expect($moved->summary)->toContain($second);
});

it('records a withdrawn promise as a null target rather than as no entry', function (): void {
    $setup = auditScenario();
    $request = auditRequest($setup);
    $owner = $setup['scenario']->owner;

    test()->actingAs($owner)->postJson("/api/client-requests/{$request['id']}/state", ['state' => 'accepted']);
    test()->actingAs($owner)->postJson("/api/client-requests/{$request['id']}/promise", [
        'promised_at' => now()->addDays(4)->toDateString(),
    ])->assertOk();
    test()->actingAs($owner)->postJson("/api/client-requests/{$request['id']}/promise", [])->assertOk();

    $withdrawn = AuditLog::query()
        ->where('action', AuditAction::ClientRequestPromised->value)
        ->where('auditable_id', $request['id'])
        ->get()
        ->filter(static function (AuditLog $entry): bool {
            /** @var array<string, mixed> $changes */
            $changes = $entry->changes;

            /*
             * `array_key_exists`, not `??`. The null coalescing operator cannot tell a key that is absent from a
             * key whose value is null, and null is exactly the value this looks for — so `?? 'unset'` matched
             * nothing and the assertion failed for a reason that had nothing to do with the code under test.
             */
            return array_key_exists('to', $changes) && $changes['to'] === null;
        });

    /*
     * Taking a promise back is an event. An entry only for the setting would leave a client's "you said the
     * fourth" answerable and "then you took it back" not.
     */
    expect($withdrawn)->toHaveCount(1);
    expect($withdrawn->first()?->summary)->toContain('Withdrew the promised date');
});

it('records a decline with the reason the client is shown, and how long they had waited', function (): void {
    $setup = auditScenario();
    $request = auditRequest($setup, $setup['viewer']);

    DB::table('client_requests')->where('id', $request['id'])->update([
        'created_at' => now()->subDays(3),
        'first_answered_at' => null,
    ]);

    $reason = 'We cannot run both sides of a category from one account.';

    test()->actingAs($setup['manager'])
        ->postJson("/api/client-requests/{$request['id']}/state", [
            'state' => 'declined',
            'reason' => $reason,
        ])->assertOk();

    ['entry' => $entry, 'changes' => $changes] = soleEntry(
        AuditAction::ClientRequestMoved,
        $request['id'],
    );

    expect((string) $entry->actor_id)->toBe((string) $setup['manager']->getKey());
    expect($changes['from'])->toBe('submitted');
    expect($changes['to'])->toBe('declined');
    expect($changes['reason'])->toBe($reason);
    /*
     * Recorded at the time rather than reconstructed later. Reconstructing it means trusting that nothing else
     * moved the timestamps in between, and this is the figure somebody asks about.
     */
    expect($changes['client_had_waited_days'])->toBe(3);
});

it('records a message by its audience, and does not copy the message into the log', function (): void {
    $setup = auditScenario();
    $request = auditRequest($setup, $setup['viewer']);
    $secret = 'This client always asks for this and never approves the creative.';

    test()->actingAs($setup['manager'])
        ->postJson("/api/client-requests/{$request['id']}/messages", [
            'audience' => 'internal',
            'body' => $secret,
        ])->assertCreated();

    ['entry' => $entry, 'changes' => $changes] = soleEntry(
        AuditAction::ClientRequestAnswered,
        $request['id'],
    );

    expect($changes['audience'])->toBe('internal');
    expect($changes['from_client'])->toBeFalse();
    // An internal note is not an answer to the client, and the entry says so.
    expect($changes['was_first_answer'])->toBeFalse();

    /*
     * The audit log is not a second place a client's internal notes live. A log that copied message bodies would
     * be a second disclosure surface with none of the module's visibility rules on it.
     */
    expect(json_encode($entry->changes))->not->toContain('never approves the creative');
    expect($entry->summary)->not->toContain('never approves the creative');
});

it('records a change of owner with both names answerable', function (): void {
    $setup = auditScenario();
    $request = auditRequest($setup);
    $owner = $setup['scenario']->owner;

    test()->actingAs($owner)
        ->postJson("/api/client-requests/{$request['id']}/owner", [
            'owner_id' => (string) $setup['manager']->getKey(),
        ])->assertOk();

    $entries = AuditLog::query()
        ->where('action', AuditAction::ClientRequestOwnerChanged->value)
        ->where('auditable_id', $request['id'])
        ->get();

    expect($entries)->toHaveCount(1);

    $assignment = $entries->first();

    expect($assignment)->not->toBeNull();

    /** @var AuditLog $assignment */
    /** @var array<string, mixed> $changes */
    $changes = $assignment->changes;

    // Both sides, so "who was handling this in March" is answerable from the record.
    expect($changes['from'])->toBeNull();
    expect($changes['to'])->toBe((string) $setup['manager']->getKey());

    test()->actingAs($owner)
        ->postJson("/api/client-requests/{$request['id']}/owner", [])
        ->assertOk();

    $cleared = AuditLog::query()
        ->where('action', AuditAction::ClientRequestOwnerChanged->value)
        ->where('auditable_id', $request['id'])
        ->get()
        ->filter(static function (AuditLog $entry): bool {
            /** @var array<string, mixed> $changes */
            $changes = $entry->changes;

            /*
             * `array_key_exists`, not `??`. The null coalescing operator cannot tell a key that is absent from a
             * key whose value is null, and null is exactly the value this looks for — so `?? 'unset'` matched
             * nothing and the assertion failed for a reason that had nothing to do with the code under test.
             */
            return array_key_exists('to', $changes) && $changes['to'] === null;
        });

    expect($cleared)->toHaveCount(1);
    expect($cleared->first()?->summary)->toContain('Removed the owner');
});

it('writes nothing to the trail for a read', function (): void {
    $setup = auditScenario();
    $request = auditRequest($setup);

    $before = AuditLog::query()->count();

    test()->actingAs($setup['viewer'])->getJson("/api/client-requests/{$request['id']}")->assertOk();
    test()->actingAs($setup['manager'])->getJson('/api/client-requests/queue')->assertOk();

    /*
     * An audit log that grew on every page view would be a log nobody reads, and this module's entries are the
     * ones somebody has to be able to find.
     */
    expect(AuditLog::query()->count())->toBe($before);
});
