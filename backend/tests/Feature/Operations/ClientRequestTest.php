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
| Client requests
|--------------------------------------------------------------------------
|
| The most important tests in this file are the leaks that do not happen.
|
| An agency's internal note about a client must never reach that client, and neither must the tasks it raised to
| answer them. Both are excluded in SQL rather than filtered afterwards, and both are asserted from the client's
| own session rather than from a unit test of a filter.
|
| The rest is about the two halves of why an intake queue is slow: requests the agency has not answered, and
| requests waiting on the client. A single "open" figure lets the second hide behind the first, and only one of
| them is the agency's to fix.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project, viewer: User} */
function intakeScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    /*
     * The client viewer, on the project. That assignment is what the client-facing side rests on: visibility comes
     * from projects, exactly as it does on every other surface.
     */
    $viewer = $scenario->addMember(SystemRole::ClientViewer);

    ProjectMember::query()->create([
        'project_id' => $project->getKey(),
        'user_id' => $viewer->getKey(),
        'role_id' => $scenario->role(SystemRole::ClientViewer)->getKey(),
    ]);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project, 'viewer' => $viewer];
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project, viewer: User}  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function raiseRequest(array $setup, array $overrides = [], ?User $as = null): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($as ?? $setup['scenario']->owner)
        ->postJson('/api/client-requests', [
            'client_id' => (string) $setup['client']->getKey(),
            'project_id' => (string) $setup['project']->getKey(),
            'kind' => 'change',
            'title' => 'Pause the retargeting over Eid',
            'detail' => 'We will be closed for the holiday.',
            ...$overrides,
        ])
        ->assertCreated()
        ->json('data');
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project, viewer: User}  $setup
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function readIntake(array $setup, ?User $as = null, array $query = []): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($as ?? $setup['scenario']->owner)
        ->getJson('/api/client-requests/queue'.($query === [] ? '' : '?'.http_build_query($query)))
        ->assertOk()
        ->json('data');
}

describe('what a client can and cannot see', function (): void {
    it('never shows an internal note to the client it is about', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/messages", [
                'audience' => 'internal',
                'body' => 'This client always asks for this and never approves the creative.',
            ])->assertCreated();

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/messages", [
                'audience' => 'client',
                'body' => 'We can do that — paused from the 28th.',
            ])->assertCreated();

        $mine = test()->actingAs($setup['viewer'])
            ->getJson("/api/client-requests/{$request['id']}")
            ->assertOk()
            ->json('data');

        /*
         * The most important assertion in this module. The note is excluded in the WHERE clause, not filtered
         * after loading — a row that was selected has been hydrated and is one refactor from a response.
         */
        $bodies = array_column($mine['messages'], 'body');

        expect($bodies)->toBe(['We can do that — paused from the 28th.']);
        expect(json_encode($mine))->not->toContain('never approves the creative');
    });

    it('shows the agency both sides', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup);

        foreach (['internal', 'client'] as $audience) {
            test()->actingAs($setup['scenario']->owner)
                ->postJson("/api/client-requests/{$request['id']}/messages", [
                    'audience' => $audience,
                    'body' => 'A '.$audience.' message.',
                ])->assertCreated();
        }

        $agency = test()->actingAs($setup['scenario']->owner)
            ->getJson("/api/client-requests/{$request['id']}")
            ->assertOk()
            ->json('data');

        expect($agency['messages'])->toHaveCount(2);
        expect($agency['is_agency_view'] ?? true)->toBeTrue();
    });

    it('never shows the client the tasks raised to answer them', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup);

        test()->actingAs($setup['scenario']->owner)->postJson('/api/tasks', [
            'title' => 'Ask the buyer whether pausing loses the learning phase',
            'client_id' => (string) $setup['client']->getKey(),
            'project_id' => (string) $setup['project']->getKey(),
            'subject_type' => 'client_request',
            'subject_id' => $request['id'],
        ])->assertCreated();

        $agency = test()->actingAs($setup['scenario']->owner)
            ->getJson("/api/client-requests/{$request['id']}")->assertOk()->json('data');
        $mine = test()->actingAs($setup['viewer'])
            ->getJson("/api/client-requests/{$request['id']}")->assertOk()->json('data');

        /*
         * The agency's plan of work — its assignees, its estimates, its internal wording — is the same leak an
         * internal note is.
         */
        expect($agency['tasks'])->toHaveCount(1);
        expect($mine['tasks'])->toBe([]);
    });

    it('forces a client\'s own message to be visible to the client', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup);

        test()->actingAs($setup['viewer'])
            ->postJson("/api/client-requests/{$request['id']}/messages", [
                // Sent as internal, deliberately. A client filing their own words as internal would hide them
                // from the person who wrote them, so the use case overrides it and a CHECK agrees.
                'audience' => 'internal',
                'body' => 'Any update on this?',
            ])->assertCreated();

        $mine = test()->actingAs($setup['viewer'])
            ->getJson("/api/client-requests/{$request['id']}")->assertOk()->json('data');

        expect(array_column($mine['messages'], 'body'))->toContain('Any update on this?');
        expect($mine['messages'][0]['is_from_client'])->toBeTrue();
        expect($mine['messages'][0]['audience'])->toBe('client');
    });

    it('keeps another client\'s requests out of this client\'s view', function (): void {
        $setup = intakeScenario();
        raiseRequest($setup, ['title' => 'Mine']);

        $other = Client::factory()->create(['workspace_id' => $setup['scenario']->workspace->getKey()]);
        $otherProject = Project::factory()->forClient($other)->create(['name' => 'Elsewhere']);

        test()->actingAs($setup['scenario']->owner)->postJson('/api/client-requests', [
            'client_id' => (string) $other->getKey(),
            'project_id' => (string) $otherProject->getKey(),
            'kind' => 'change',
            'title' => 'Not mine',
        ])->assertCreated();

        $queue = readIntake($setup, $setup['viewer']);
        $titles = [];

        foreach ($queue['load']['by_state'] as $count) {
            expect($count)->toBeGreaterThanOrEqual(0);
        }

        foreach ($queue['columns'] as $column) {
            foreach ($column['requests'] as $request) {
                $titles[] = $request['title'];
            }
        }

        // The same project-based rule every other surface uses, which is why there is no second rule to drift.
        expect($titles)->toBe(['Mine']);
        expect($queue['load']['total'])->toBe(1);
    });

    it('tells the interface which side it is showing', function (): void {
        $setup = intakeScenario();
        raiseRequest($setup);

        /*
         * A page that did not know it was the client's view would offer an "internal note" control that silently
         * produced a client-visible message.
         */
        expect(readIntake($setup)['is_agency_view'])->toBeTrue();
        expect(readIntake($setup, $setup['viewer'])['is_agency_view'])->toBeFalse();
        expect(readIntake($setup, $setup['viewer'])['can_triage'])->toBeFalse();
    });

    it('refuses to let a client triage their own request', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup);

        test()->actingAs($setup['viewer'])
            ->postJson("/api/client-requests/{$request['id']}/state", ['state' => 'accepted'])
            ->assertForbidden();
    });

    it('refuses to let a media buyer accept on the agency\'s behalf', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup);
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);

        ProjectMember::query()->create([
            'project_id' => $setup['project']->getKey(),
            'user_id' => $buyer->getKey(),
            'role_id' => $setup['scenario']->role(SystemRole::MediaBuyer)->getKey(),
        ]);

        /*
         * Agreeing to work for a client and committing a date is the conversation a project manager owns. A queue
         * where anybody could accept is one where the agency has promised things nobody tracked.
         */
        test()->actingAs($buyer)
            ->postJson("/api/client-requests/{$request['id']}/state", ['state' => 'accepted'])
            ->assertForbidden();
    });
});

describe('raising one', function (): void {
    it('records a client\'s own request as coming from the client, and unanswered', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup, [], $setup['viewer']);

        expect($request['state'])->toBe('submitted');
        /*
         * Unanswered until somebody says something. An agency member filing on a client's behalf counts as
         * answered, because the conversation happened before the row existed.
         */
        expect($request['is_unanswered'])->toBeTrue();

        $entry = AuditLog::query()
            ->where('action', AuditAction::ClientRequestSubmitted->value)
            ->where('auditable_id', $request['id'])
            ->firstOrFail();

        /** @var array<string, mixed> $changes */
        $changes = $entry->changes;

        expect($changes['from_client'])->toBeTrue();
    });

    it('counts an agency member\'s own filing as already answered', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup);

        // Otherwise a fictional client wait sits at the top of the queue.
        expect($request['is_unanswered'])->toBeFalse();
    });

    it('defaults the urgency to the mildest value, never to urgent', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup);

        // Defaulting to urgent would make every request urgent, and put words in a client's mouth.
        expect($request['stated_urgency'])->toBe('whenever');
    });

    it('refuses a request with no client', function (): void {
        $setup = intakeScenario();

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/client-requests', ['kind' => 'change', 'title' => 'Something'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'client_required');
    });
});

describe('triage', function (): void {
    it('refuses to decline without a reason the client will be shown', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/state", ['state' => 'declined'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'reason_required');
    });

    it('refuses to ask the client for information without saying what', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/state", ['state' => 'needs_information'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'reason_required');
    });

    it('shows the client the decline reason', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup, [], $setup['viewer']);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/state", [
                'state' => 'declined',
                'reason' => 'We cannot run both sides of a category from one account.',
            ])->assertOk();

        $mine = test()->actingAs($setup['viewer'])
            ->getJson("/api/client-requests/{$request['id']}")->assertOk()->json('data');

        /*
         * The whole reason the column exists. A request that vanished from an intake queue with nobody told why is
         * the worst thing this module could do.
         */
        expect($mine['declined_reason'])->toBe('We cannot run both sides of a category from one account.');
    });

    it('stamps the first answer once, and keeps it', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup, [], $setup['viewer']);
        $owner = $setup['scenario']->owner;

        test()->actingAs($owner)->postJson("/api/client-requests/{$request['id']}/state", [
            'state' => 'accepted',
        ])->assertOk();

        $first = DB::table('client_requests')->where('id', $request['id'])->value('first_answered_at');

        test()->actingAs($owner)->postJson("/api/client-requests/{$request['id']}/state", [
            'state' => 'in_progress',
        ])->assertOk();

        // The figure measures how long the *first* answer took. A later move must not reset it.
        expect(DB::table('client_requests')->where('id', $request['id'])->value('first_answered_at'))
            ->toBe($first);
    });

    it('does not count an internal note as answering the client', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup, [], $setup['viewer']);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/messages", [
                'audience' => 'internal',
                'body' => 'Who owns this one?',
            ])->assertCreated();

        /*
         * Writing to each other is not answering. A figure that counted it would report a responsive queue while
         * the client heard nothing.
         */
        expect(DB::table('client_requests')->where('id', $request['id'])->value('first_answered_at'))
            ->toBeNull();
    });

    it('refuses a move the queue does not allow, and says where it can go', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/state", ['state' => 'delivered'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_transition')
            ->assertJsonPath('allowed.1', 'accepted');
    });

    it('lets delivered work be reopened', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup);
        $owner = $setup['scenario']->owner;

        foreach (['accepted', 'in_progress', 'delivered'] as $state) {
            test()->actingAs($owner)
                ->postJson("/api/client-requests/{$request['id']}/state", ['state' => $state])
                ->assertOk();
        }

        /*
         * "This is not what I asked for" is the most important message in this module, and a terminal state would
         * make the honest response a second request that loses the history of the first.
         */
        $reopened = test()->actingAs($owner)
            ->postJson("/api/client-requests/{$request['id']}/state", ['state' => 'in_progress'])
            ->assertOk()
            ->json('data');

        expect($reopened['state'])->toBe('in_progress');
    });
});

describe('promises', function (): void {
    it('records the person who promised it', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup);
        $owner = $setup['scenario']->owner;

        test()->actingAs($owner)->postJson("/api/client-requests/{$request['id']}/state", [
            'state' => 'accepted',
        ])->assertOk();

        $promised = test()->actingAs($owner)->postJson("/api/client-requests/{$request['id']}/promise", [
            'promised_at' => now()->addDays(8)->toDateString(),
        ])->assertOk()->json('data');

        /*
         * A date with nobody behind it is one the client will quote and nobody will own. What the *audit trail*
         * records about a promise — both sides of a change to it, and who made it — is asserted in
         * ClientRequestAuditTest, which is the file that owns those assertions deterministically.
         */
        expect($promised['promised_at'])->toBe(now()->addDays(8)->toDateString());
        expect($promised['promised_by_name'])->toBe($owner->name);
    });

    it('clears the person when the promise is withdrawn', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup);
        $owner = $setup['scenario']->owner;

        test()->actingAs($owner)->postJson("/api/client-requests/{$request['id']}/state", ['state' => 'accepted']);
        test()->actingAs($owner)->postJson("/api/client-requests/{$request['id']}/promise", [
            'promised_at' => now()->addDays(8)->toDateString(),
        ])->assertOk();

        $cleared = test()->actingAs($owner)
            ->postJson("/api/client-requests/{$request['id']}/promise", [])
            ->assertOk()
            ->json('data');

        // Both or neither. The CHECK says the same thing from below.
        expect($cleared['promised_at'])->toBeNull();
        expect($cleared['promised_by_name'])->toBeNull();
    });
});

describe('the figures', function (): void {
    it('separates what the agency is holding up from what is waiting on the client', function (): void {
        $setup = intakeScenario();
        $owner = $setup['scenario']->owner;

        raiseRequest($setup, ['title' => 'Nobody has looked at this']);
        $asked = raiseRequest($setup, ['title' => 'We asked them something']);

        test()->actingAs($owner)->postJson("/api/client-requests/{$asked['id']}/state", [
            'state' => 'needs_information',
            'reason' => 'We need the opening date.',
        ])->assertOk();

        $queue = readIntake($setup);

        /*
         * A single "open" figure would be two, and would let a conversation stalled on the client hide behind the
         * same column as work the agency has not started. Only one of them is the agency's to fix.
         */
        expect($queue['load']['open'])->toBe(2);
        expect($queue['load']['on_the_agency'])->toBe(1);
        expect($queue['load']['on_the_client'])->toBe(1);
    });

    it('publishes accepted work with no promised date', function (): void {
        $setup = intakeScenario();
        $owner = $setup['scenario']->owner;

        $promised = raiseRequest($setup, ['title' => 'Promised']);
        $unpromised = raiseRequest($setup, ['title' => 'Not promised']);

        foreach ([$promised, $unpromised] as $request) {
            test()->actingAs($owner)
                ->postJson("/api/client-requests/{$request['id']}/state", ['state' => 'accepted'])
                ->assertOk();
        }

        test()->actingAs($owner)->postJson("/api/client-requests/{$promised['id']}/promise", [
            'promised_at' => now()->addDays(5)->toDateString(),
        ])->assertOk();

        $queue = readIntake($setup);

        /*
         * The figure a queue usually hides. "We are on it" is the whole of what the unpromised client was told,
         * and a queue with no promises cannot be late — nor said to be on time.
         */
        expect($queue['load']['promised'])->toBe(1);
        expect($queue['load']['unpromised'])->toBe(1);
        expect((float) $queue['load']['promised_share'])->toBe(50.0);
    });

    it('reports how long the oldest unanswered request has waited', function (): void {
        $setup = intakeScenario();
        $old = raiseRequest($setup, ['title' => 'Ignored'], $setup['viewer']);

        DB::table('client_requests')->where('id', $old['id'])->update([
            'created_at' => now()->subDays(5),
            'first_answered_at' => null,
        ]);

        $queue = readIntake($setup);

        expect($queue['load']['oldest_unanswered_days'])->toBe(5);
        expect($queue['load']['needs_attention'])->toBeTrue();
        expect((int) $queue['load']['patience_days'])->toBe(2);
    });

    it('has no rates until something has closed', function (): void {
        $setup = intakeScenario();
        raiseRequest($setup);

        expect(readIntake($setup)['load']['delivery_rate'])->toBeNull();
    });

    it('counts what clients called urgent without turning it into a priority', function (): void {
        $setup = intakeScenario();

        raiseRequest($setup, ['title' => 'Urgent one', 'stated_urgency' => 'urgent'], $setup['viewer']);
        raiseRequest($setup, ['title' => 'Relaxed one', 'stated_urgency' => 'whenever'], $setup['viewer']);

        $queue = readIntake($setup);

        /*
         * Published under its own name. Nothing in this product turns a client's stated urgency into the board's
         * priority — that would let a client set an agency's priorities by typing.
         */
        expect($queue['load']['called_urgent'])->toBe(1);
        expect($queue['load']['by_urgency']['urgent'])->toBe(1);
    });

    it('takes column totals from the whole queue rather than the cards on it', function (): void {
        $setup = intakeScenario();

        foreach (range(1, 4) as $index) {
            raiseRequest($setup, ['title' => 'Request '.$index]);
        }

        $queue = readIntake($setup, null, ['per_column' => 2]);
        $submitted = array_values(array_filter(
            $queue['columns'],
            static fn (array $column): bool => $column['state'] === 'submitted',
        ))[0];

        expect($submitted['requests'])->toHaveCount(2);
        expect($submitted['total'])->toBe(4);
    });

    it('narrows to what the agency is holding up', function (): void {
        $setup = intakeScenario();
        $owner = $setup['scenario']->owner;

        raiseRequest($setup, ['title' => 'On us']);
        $asked = raiseRequest($setup, ['title' => 'On them']);

        test()->actingAs($owner)->postJson("/api/client-requests/{$asked['id']}/state", [
            'state' => 'needs_information',
            'reason' => 'We need a date.',
        ])->assertOk();

        expect(readIntake($setup, null, ['on_the_agency' => '1'])['load']['total'])->toBe(1);
    });
});

describe('the vocabulary', function (): void {
    it('publishes both halves of why a queue is slow, and the client\'s own urgency scale', function (): void {
        $setup = intakeScenario();

        $data = test()->actingAs($setup['scenario']->owner)
            ->getJson('/api/client-requests/vocabulary')
            ->assertOk()
            ->json('data');

        $waiting = array_values(array_filter(
            $data['states'],
            static fn (array $state): bool => $state['state'] === 'needs_information',
        ))[0];

        expect($waiting['is_on_the_client'])->toBeTrue();
        expect($waiting['is_on_the_agency'])->toBeFalse();
        expect($waiting['needs_reason'])->toBeTrue();

        /*
         * Its own scale with its own name, so a front end cannot reuse the task priority labels and present a
         * client's claim as the agency's judgement.
         */
        expect(array_column($data['urgencies'], 'urgency'))
            ->toBe(['whenever', 'this_month', 'this_week', 'urgent']);
    });
});

describe('raising work for a request', function (): void {
    it('links a task to the request and leaves the request a request', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup);
        $owner = $setup['scenario']->owner;

        test()->actingAs($owner)->postJson("/api/client-requests/{$request['id']}/state", [
            'state' => 'accepted',
        ])->assertOk();

        $after = test()->actingAs($owner)
            ->postJson("/api/client-requests/{$request['id']}/tasks", [
                'title' => 'Check whether pausing loses the learning phase',
                'priority' => 'high',
            ])
            ->assertCreated()
            ->json('data');

        /*
         * The request is still a request, with its own state and its own conversation. An intake queue whose rows
         * vanish into a task board is one where nobody can tell a client what happened to what they asked for.
         */
        expect($after['state'])->toBe('accepted');
        expect($after['tasks'])->toHaveCount(1);
        expect($after['tasks'][0]['title'])->toBe('Check whether pausing loses the learning phase');
    });

    it('does not take the task\'s priority from what the client said', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup, ['stated_urgency' => 'urgent'], $setup['viewer']);
        $owner = $setup['scenario']->owner;

        test()->actingAs($owner)->postJson("/api/client-requests/{$request['id']}/state", [
            'state' => 'accepted',
        ])->assertOk();

        test()->actingAs($owner)->postJson("/api/client-requests/{$request['id']}/tasks", [
            'title' => 'Do the thing',
        ])->assertCreated();

        $task = DB::table('tasks')->where('subject_id', $request['id'])->first();

        /*
         * The client called it urgent; the board calls it normal until somebody decides otherwise. A client who
         * could set the agency's priorities by typing would set all of them.
         */
        expect($task?->priority)->toBe('normal');
    });

    it('refuses to raise work for a question', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup, ['kind' => 'question', 'title' => 'Why did the cost jump?']);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/tasks", ['title' => 'Investigate'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'questions_need_an_answer');
    });

    it('refuses to raise work for a settled request, and says to reopen it', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/state", [
                'state' => 'declined',
                'reason' => 'Out of scope for this engagement.',
            ])->assertOk();

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/tasks", ['title' => 'Do it anyway'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'settled_needs_no_work');
    });

    it('refuses to let a client raise work on the agency\'s board', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup, [], $setup['viewer']);

        test()->actingAs($setup['viewer'])
            ->postJson("/api/client-requests/{$request['id']}/tasks", ['title' => 'Please do this'])
            ->assertForbidden();
    });
});

describe('owning one', function (): void {
    it('puts somebody\'s name on a request, and takes it off again', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup);
        $manager = $setup['scenario']->addMember(SystemRole::ProjectManager);

        ProjectMember::query()->create([
            'project_id' => $setup['project']->getKey(),
            'user_id' => $manager->getKey(),
            'role_id' => $setup['scenario']->role(SystemRole::ProjectManager)->getKey(),
        ]);

        $assigned = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/owner", [
                'owner_id' => (string) $manager->getKey(),
            ])
            ->assertOk()
            ->json('data');

        expect($assigned['owner_name'])->toBe($manager->name);

        $cleared = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/owner", [])
            ->assertOk()
            ->json('data');

        // A queue that can only ever add an owner accumulates requests owned by whoever opened them first.
        expect($cleared['owner_name'])->toBeNull();
    });

    it('never shows the client who owns their request', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup, [], $setup['viewer']);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/owner", [
                'owner_id' => (string) $setup['scenario']->owner->getKey(),
            ])->assertOk();

        $mine = test()->actingAs($setup['viewer'])
            ->getJson("/api/client-requests/{$request['id']}")->assertOk()->json('data');

        /*
         * Who inside an agency is handling something is the agency's business. It is also the field a client
         * would chase by name, which is a conversation the agency should choose to have rather than have forced.
         */
        expect($mine['owner_name'])->toBeNull();
        expect($mine['submitted_by_name'])->toBeNull();

        // And the agency still sees it, which is the half that makes the queue usable.
        $agency = test()->actingAs($setup['scenario']->owner)
            ->getJson("/api/client-requests/{$request['id']}")->assertOk()->json('data');

        expect($agency['owner_name'])->not->toBeNull();
    });

    it('does show the client who promised them a date', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup, [], $setup['viewer']);
        $owner = $setup['scenario']->owner;

        test()->actingAs($owner)->postJson("/api/client-requests/{$request['id']}/state", [
            'state' => 'accepted',
        ])->assertOk();
        test()->actingAs($owner)->postJson("/api/client-requests/{$request['id']}/promise", [
            'promised_at' => now()->addDays(5)->toDateString(),
        ])->assertOk();

        $mine = test()->actingAs($setup['viewer'])
            ->getJson("/api/client-requests/{$request['id']}")->assertOk()->json('data');

        /*
         * The one name a client does see, and deliberately so: a promise was made **to** them, so who made it is
         * part of the promise rather than an internal detail. Withholding it would leave a commitment with nobody
         * behind it, which is the thing the schema's CHECK exists to prevent.
         */
        expect($mine['promised_by_name'])->toBe($owner->name);
        expect($mine['promised_at'])->toBe(now()->addDays(5)->toDateString());
    });

    it('refuses to let a client assign an owner', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup, [], $setup['viewer']);

        test()->actingAs($setup['viewer'])
            ->postJson("/api/client-requests/{$request['id']}/owner", [
                'owner_id' => (string) $setup['viewer']->getKey(),
            ])
            ->assertForbidden();
    });
});

describe('telling the client', function (): void {
    it('tells whoever raised it when the request moves', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup, [], $setup['viewer']);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/state", ['state' => 'accepted'])
            ->assertOk();

        $feed = test()->actingAs($setup['viewer'])
            ->getJson('/api/notifications')
            ->assertOk()
            ->json('data');

        /*
         * The loop the intake queue opens, closed. A request accepted with nobody told is a client checking an
         * inbox that never says anything — the same failure as requiring a decline reason and never showing it.
         */
        $row = jsonRow($feed['notifications'], 'kind', 'client_request_moved');

        expect($row['title'])->toBe('Pause the retargeting over Eid');
        expect($row['body'])->toContain('Accepted');

        /* Told, not tasked. Nothing to do about "we accepted it". */
        expect($row['needs_action'])->toBeFalse();
    });

    it('tells the client, with something to do, when the agency needs information', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup, [], $setup['viewer']);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/state", [
                'state' => 'needs_information',
                'reason' => 'Which campaigns should stay on over the holiday?',
            ])
            ->assertOk();

        $feed = test()->actingAs($setup['viewer'])->getJson('/api/notifications')->assertOk()->json('data');
        $row = jsonRow($feed['notifications'], 'kind', 'client_request_needs_information');

        /*
         * Its own kind, because this is the one move that puts the work back on the client — and the only figure
         * a notification centre leads with usefully is "waiting on you".
         */
        expect($row['needs_action'])->toBeTrue();
        expect($row['body'])->toContain('Which campaigns should stay on');
        expect($feed['digest']['waiting'])->toBeGreaterThan(0);
    });

    it('tells the client when the agency answers, and never when it writes to itself', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup, [], $setup['viewer']);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/messages", [
                'audience' => 'internal',
                'body' => 'Low priority — their spend is paused anyway.',
            ])
            ->assertCreated();

        $afterNote = test()->actingAs($setup['viewer'])
            ->getJson('/api/notifications')
            ->assertOk()
            ->json('data.notifications');

        /*
         * The cruellest bug this module could have: a bell saying "the agency replied" for a note the client
         * cannot read. An internal note announces nothing at all — not a quieter notification, none.
         */
        expect(array_column($afterNote, 'kind'))->not->toContain('client_request_answered');

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/messages", [
                'audience' => 'client',
                'body' => 'We can pause everything except the brand campaign.',
            ])
            ->assertCreated();

        $afterAnswer = test()->actingAs($setup['viewer'])
            ->getJson('/api/notifications')
            ->assertOk()
            ->json('data.notifications');

        expect(array_column($afterAnswer, 'kind'))->toContain('client_request_answered');

        /*
         * And the message itself is not in the notification. It is already on the request, where the audience
         * rule decides who may read it, and copying it here would be a second place that rule has to hold.
         */
        $row = jsonRow($afterAnswer, 'kind', 'client_request_answered');
        expect($row['body'])->not->toContain('brand campaign');
    });

    it('tells the agency when a client replies, which nothing used to', function (): void {
        $setup = intakeScenario();
        $request = raiseRequest($setup, [], $setup['viewer']);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/state", ['state' => 'accepted'])
            ->assertOk();

        test()->actingAs($setup['viewer'])
            ->postJson("/api/client-requests/{$request['id']}/messages", [
                'audience' => 'client',
                'body' => 'One more thing — can you also pause the search campaign?',
            ])
            ->assertCreated();

        $feed = test()->actingAs($setup['scenario']->owner)
            ->getJson('/api/notifications')
            ->assertOk()
            ->json('data');

        /*
         * The gap `client_request_submitted` left: a request answered once and then replied to is a client
         * waiting again, and nothing was telling anybody.
         */
        $row = jsonRow($feed['notifications'], 'kind', 'client_request_replied');

        expect($row['needs_action'])->toBeTrue();
        expect($row['title'])->toBe('Pause the retargeting over Eid');
    });

    it('does not tell somebody about a move they made themselves', function (): void {
        $setup = intakeScenario();
        /* Raised by the owner this time, who then moves it. */
        $request = raiseRequest($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/state", ['state' => 'accepted'])
            ->assertOk();

        $feed = test()->actingAs($setup['scenario']->owner)
            ->getJson('/api/notifications')
            ->assertOk()
            ->json('data.notifications');

        // A bell that reports your own actions back to you is a bell people turn off.
        expect(array_column($feed, 'kind'))->not->toContain('client_request_moved');
    });

    it('never tells a client about another client\'s request', function (): void {
        $setup = intakeScenario();

        $otherClient = Client::factory()->create(['workspace_id' => $setup['scenario']->workspace->getKey()]);
        $otherProject = Project::factory()->forClient($otherClient)->create(['name' => 'Other']);

        $request = raiseRequest($setup, [
            'client_id' => (string) $otherClient->getKey(),
            'project_id' => (string) $otherProject->getKey(),
            'title' => 'Something for somebody else',
        ]);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/state", ['state' => 'accepted'])
            ->assertOk();

        $feed = test()->actingAs($setup['viewer'])
            ->getJson('/api/notifications')
            ->assertOk()
            ->json('data.notifications');

        /*
         * The notification goes to the **submitter**, and nobody else — which is what makes a single named
         * recipient with no project scope safe. A client viewer on an unrelated project learns nothing.
         */
        expect($feed)->toBe([]);
    });
});
