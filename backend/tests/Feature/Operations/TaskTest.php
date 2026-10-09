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
| The operations board
|--------------------------------------------------------------------------
|
| What is tested here is mostly what the board refuses and what it publishes rather than hides.
|
| The refusals: blocked and cancelled must say why, blocked work cannot jump to done, and a client viewer
| cannot see an agency's board at all.
|
| What it publishes: the undated count beside the overdue one, the unassigned pile as a row of the workload,
| and column totals that come from the whole board rather than from the cards on screen.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project} */
function taskScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project];
}

/**
 * Open a task and return its id.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @param  array<string, mixed>  $overrides
 */
function openTask(array $setup, array $overrides = []): string
{
    /** @var string */
    return test()->actingAs($setup['scenario']->owner)
        ->postJson('/api/tasks', [
            'client_id' => (string) $setup['client']->getKey(),
            'project_id' => (string) $setup['project']->getKey(),
            'title' => 'Rebuild the Ramadan audience lists',
            'priority' => 'high',
            ...$overrides,
        ])
        ->assertCreated()
        ->json('data.id');
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function readBoard(array $setup, array $query = []): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($setup['scenario']->owner)
        ->getJson('/api/tasks/board'.($query === [] ? '' : '?'.http_build_query($query)))
        ->assertOk()
        ->json('data');
}

/**
 * Put somebody on the project, which is what makes work on it visible to them.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 */
function putOnProject(array $setup, User $user, SystemRole $role): void
{
    ProjectMember::query()->create([
        'project_id' => $setup['project']->getKey(),
        'user_id' => $user->getKey(),
        'role_id' => $setup['scenario']->role($role)->getKey(),
    ]);
}

describe('opening work', function (): void {
    it('starts a task in to do, at normal priority unless told otherwise', function (): void {
        $setup = taskScenario();

        $data = test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/tasks', ['title' => 'Audit the UTM naming'])
            ->assertCreated()
            ->json('data');

        expect($data['state'])->toBe('todo');
        // A default of urgent is how nothing stays urgent.
        expect($data['priority'])->toBe('normal');
        expect($data['due_on'])->toBeNull();
        expect($data['horizon'])->toBe('undated');
    });

    it('refuses a task with no title', function (): void {
        $setup = taskScenario();

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/tasks', ['title' => '   '])
            ->assertStatus(422)
            ->assertJsonPath('code', 'title_required');
    });

    it('refuses half a link to what the work is about', function (): void {
        $setup = taskScenario();

        /*
         * Refused rather than dropped. A type with no identifier renders as a link and goes nowhere, and
         * silently discarding it produces a task detached from the thing it was created for.
         */
        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/tasks', ['title' => 'Chase the brief', 'subject_type' => 'creative_request'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_subject')
            ->assertJsonPath('allowed.0', 'creative_request');
    });

    it('names an unrecognised priority rather than quietly using the default', function (): void {
        $setup = taskScenario();

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/tasks', ['title' => 'Something', 'priority' => 'critical'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_priority')
            ->assertJsonPath('allowed.3', 'urgent');
    });

    it('records what was asked for, including the absence of a date', function (): void {
        $setup = taskScenario();
        $id = openTask($setup);

        $entry = AuditLog::query()
            ->where('action', AuditAction::TaskOpened->value)
            ->where('auditable_id', $id)
            ->firstOrFail();

        /** @var array<string, mixed> $changes */
        $changes = $entry->changes;

        expect($changes['priority'])->toBe('high');
        /*
         * Recorded as null rather than omitted. A task opened with no date is the one worth finding later,
         * because it is the one the overdue figure cannot see.
         */
        expect($changes)->toHaveKey('due_on');
        expect($changes['due_on'])->toBeNull();
    });
});

describe('moving work', function (): void {
    it('refuses to block work without saying what is blocking it', function (): void {
        $setup = taskScenario();
        $id = openTask($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/tasks/{$id}/state", ['state' => 'blocked'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_transition')
            // The sentence, not the code, is what makes somebody supply the reason.
            ->assertJsonPath('message', 'Say what is blocking it. A blocked card without a reason is one nobody can clear.');
    });

    it('refuses to cancel work without saying why', function (): void {
        $setup = taskScenario();
        $id = openTask($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/tasks/{$id}/state", ['state' => 'cancelled'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_transition');
    });

    it('cancels with a reason and keeps it', function (): void {
        $setup = taskScenario();
        $id = openTask($setup);

        $data = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/tasks/{$id}/state", [
                'state' => 'cancelled',
                'reason' => 'The client postponed the activity to next quarter.',
            ])
            ->assertOk()
            ->json('data');

        expect($data['state'])->toBe('cancelled');
        expect($data['cancelled_reason'])->toBe('The client postponed the activity to next quarter.');
    });

    it('refuses the jump from blocked to done, and says where it can go instead', function (): void {
        $setup = taskScenario();
        $id = openTask($setup);
        $owner = $setup['scenario']->owner;

        test()->actingAs($owner)->postJson("/api/tasks/{$id}/state", ['state' => 'in_progress'])->assertOk();
        test()->actingAs($owner)
            ->postJson("/api/tasks/{$id}/state", ['state' => 'blocked', 'reason' => 'Waiting on template access.'])
            ->assertOk();

        test()->actingAs($owner)
            ->postJson("/api/tasks/{$id}/state", ['state' => 'done'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_transition')
            ->assertJsonPath('allowed', ['in_progress', 'cancelled']);
    });

    it('keeps the blocking reason after the work is unblocked', function (): void {
        $setup = taskScenario();
        $id = openTask($setup);
        $owner = $setup['scenario']->owner;

        test()->actingAs($owner)->postJson("/api/tasks/{$id}/state", ['state' => 'in_progress']);
        test()->actingAs($owner)->postJson("/api/tasks/{$id}/state", [
            'state' => 'blocked',
            'reason' => "Waiting on the client's developer for template access.",
        ]);

        $data = test()->actingAs($owner)
            ->postJson("/api/tasks/{$id}/state", ['state' => 'in_progress'])
            ->assertOk()
            ->json('data');

        /*
         * Work that was blocked on a client's developer was blocked on a client's developer, and that stays
         * true afterwards. It is the answer to "why did this take three weeks".
         */
        expect($data['blocked_reason'])->toBe("Waiting on the client's developer for template access.");
    });

    it('stamps the completion time, and clears it when work is reopened', function (): void {
        $setup = taskScenario();
        $id = openTask($setup);
        $owner = $setup['scenario']->owner;

        test()->actingAs($owner)->postJson("/api/tasks/{$id}/state", ['state' => 'in_progress']);

        $done = test()->actingAs($owner)
            ->postJson("/api/tasks/{$id}/state", ['state' => 'done'])
            ->assertOk()
            ->json('data');

        expect($done['completed_at'])->not->toBeNull();

        $reopened = test()->actingAs($owner)
            ->postJson("/api/tasks/{$id}/state", ['state' => 'in_progress'])
            ->assertOk()
            ->json('data');

        /*
         * Cleared, because a CHECK requires it and because a stale completion time would quietly enter the
         * next average turnaround as though the work had finished the first time.
         */
        expect($reopened['completed_at'])->toBeNull();
    });

    it('measures working days from when somebody started, not from when it was filed', function (): void {
        $setup = taskScenario();
        $id = openTask($setup);
        $owner = $setup['scenario']->owner;

        test()->actingAs($owner)->postJson("/api/tasks/{$id}/state", ['state' => 'in_progress']);

        // Backdated by hand, because the clock cannot be moved mid-request and the arithmetic is the point.
        DB::table('tasks')->where('id', $id)->update(['started_at' => now()->subDays(2)]);

        $data = test()->actingAs($owner)
            ->postJson("/api/tasks/{$id}/state", ['state' => 'done'])
            ->assertOk()
            ->json('data');

        /*
         * A task that sat in a backlog for a month and was done in two days took two days. Measuring from
         * creation would make every estimate drawn from this figure useless.
         */
        expect($data['working_days'])->toBe(2);
    });
});

describe('the figures beside the board', function (): void {
    it('publishes the undated count beside the overdue one', function (): void {
        $setup = taskScenario();

        openTask($setup, ['due_on' => now()->subDays(3)->toDateString()]);
        openTask($setup, ['title' => 'No date on this one']);
        openTask($setup, ['title' => 'Nor this one']);

        $board = readBoard($setup);

        expect($board['workload']['overdue'])->toBe(1);
        /*
         * The figure a board usually hides. Two of these three tasks cannot be late, which is what makes
         * "one overdue" a statement about a third of the work rather than about all of it.
         */
        expect($board['workload']['undated'])->toBe(2);
        expect($board['workload']['by_horizon']['undated'])->toBe(2);
    });

    it('refuses to call the overdue figure a measure of lateness when most work is undated', function (): void {
        $setup = taskScenario();

        openTask($setup, ['due_on' => now()->subDays(1)->toDateString()]);
        openTask($setup, ['title' => 'Undated one']);
        openTask($setup, ['title' => 'Undated two']);

        $board = readBoard($setup);

        expect($board['workload']['dated_share'])->toBeLessThan(80.0);
        expect($board['workload']['is_lateness_reportable'])->toBeFalse();
        // Loosely compared: JSON renders a whole float as an integer, and the value is the point.
        expect((float) $board['workload']['dated_share_floor'])->toBe(80.0);
    });

    it('counts the unassigned pile as a row of the workload', function (): void {
        $setup = taskScenario();
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        putOnProject($setup, $buyer, SystemRole::MediaBuyer);

        openTask($setup, ['assignee_id' => (string) $buyer->getKey()]);
        openTask($setup, ['title' => 'Nobody has this']);

        $board = readBoard($setup);

        expect($board['workload']['unassigned'])->toBe(1);

        $unassigned = array_values(array_filter(
            $board['load'],
            static fn (array $row): bool => $row['is_unassigned'] === true,
        ));

        /*
         * A row rather than an omission. A workload chart of named people only shows a comfortable team
         * beside a pile of work nobody has agreed to do.
         */
        expect($unassigned)->toHaveCount(1);
        expect($unassigned[0]['open'])->toBe(1);
    });

    it('puts the unassigned pile last however big it is', function (): void {
        $setup = taskScenario();
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        putOnProject($setup, $buyer, SystemRole::MediaBuyer);

        openTask($setup, ['assignee_id' => (string) $buyer->getKey()]);

        foreach (range(1, 3) as $index) {
            openTask($setup, ['title' => 'Orphan '.$index]);
        }

        $board = readBoard($setup);
        $last = $board['load'][count($board['load']) - 1];

        // It is not a person's load, and leading with it would read as somebody called "Unassigned".
        expect($last['is_unassigned'])->toBeTrue();
    });

    it('takes column totals from the whole board rather than from the cards on it', function (): void {
        $setup = taskScenario();

        foreach (range(1, 4) as $index) {
            openTask($setup, ['title' => 'Task '.$index]);
        }

        $board = readBoard($setup, ['per_column' => 2]);
        $todo = array_values(array_filter(
            $board['columns'],
            static fn (array $column): bool => $column['state'] === 'todo',
        ))[0];

        /*
         * Two cards on screen, four tasks in the column. A board publishing one number would be publishing
         * "the first two" — a wrong figure that reads as right for months.
         */
        expect($todo['tasks'])->toHaveCount(2);
        expect($todo['total'])->toBe(4);
        expect($board['is_truncated'])->toBeTrue();
    });

    it('has no completion rate until something has settled', function (): void {
        $setup = taskScenario();
        openTask($setup);

        expect(readBoard($setup)['workload']['completion_rate'])->toBeNull();
    });
});

describe('what a task is about', function (): void {
    it('resolves the subject, and says when it has been deleted', function (): void {
        $setup = taskScenario();
        $other = Project::factory()->forClient($setup['client'])->create(['name' => 'Ramadan push']);

        $id = openTask($setup, [
            'subject_type' => 'project',
            'subject_id' => (string) $other->getKey(),
        ]);

        $data = test()->actingAs($setup['scenario']->owner)->getJson("/api/tasks/{$id}")->assertOk()->json('data');

        expect($data['subject']['label'])->toBe('Ramadan push');
        expect($data['subject']['exists'])->toBeTrue();

        // Deleted underneath the task, which is possible on purpose: there is no foreign key.
        DB::table('projects')->where('id', $other->getKey())->delete();

        $after = test()->actingAs($setup['scenario']->owner)->getJson("/api/tasks/{$id}")->assertOk()->json('data');

        /*
         * Reported as gone rather than rendered as a live link. A link that goes nowhere is worse than a
         * sentence saying the thing was deleted.
         */
        expect($after['subject']['exists'])->toBeFalse();
        expect($after['subject']['label'])->toBeNull();
    });
});

describe('changing a task', function (): void {
    it('clears a due date when one is sent empty', function (): void {
        $setup = taskScenario();
        $id = openTask($setup, ['due_on' => now()->addDays(4)->toDateString()]);

        $data = test()->actingAs($setup['scenario']->owner)
            ->patchJson("/api/tasks/{$id}", ['due_on' => ''])
            ->assertOk()
            ->json('data');

        /*
         * Removing a deadline has to be possible. A board where a date can be set but never cleared
         * accumulates deadlines nobody agreed to, and then the overdue figure is noise.
         */
        expect($data['due_on'])->toBeNull();
        expect($data['horizon'])->toBe('undated');
    });

    it('writes nothing and logs nothing when nothing was asked for', function (): void {
        $setup = taskScenario();
        $id = openTask($setup);

        test()->actingAs($setup['scenario']->owner)->patchJson("/api/tasks/{$id}", [])->assertOk();

        expect(AuditLog::query()->where('action', AuditAction::TaskUpdated->value)->count())->toBe(0);
    });

    it('logs an assignment under its own action', function (): void {
        $setup = taskScenario();
        $id = openTask($setup);
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        putOnProject($setup, $buyer, SystemRole::MediaBuyer);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/tasks/{$id}/assignee", ['assignee_id' => (string) $buyer->getKey()])
            ->assertOk();

        /*
         * Its own action rather than part of an update. "Who was asked to do this" is the entry people go
         * looking for, and a generic "updated" buries it.
         */
        expect(AuditLog::query()->where('action', AuditAction::TaskAssigned->value)->count())->toBe(1);
    });

    it('unassigns when no assignee is sent', function (): void {
        $setup = taskScenario();
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        putOnProject($setup, $buyer, SystemRole::MediaBuyer);
        $id = openTask($setup, ['assignee_id' => (string) $buyer->getKey()]);

        $data = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/tasks/{$id}/assignee", [])
            ->assertOk()
            ->json('data');

        // A board that can only ever add an owner accumulates work assigned to whoever touched it first.
        expect($data['assignee_id'])->toBeNull();
    });
});

describe('who may see the board', function (): void {
    it('refuses a client viewer outright', function (): void {
        $setup = taskScenario();
        $viewer = $setup['scenario']->addMember(SystemRole::ClientViewer);

        /*
         * An agency's board carries its own reasons for things being blocked. A client seeing them is an
         * internal conversation leaking, so the permission is withheld rather than the rows filtered.
         */
        test()->actingAs($viewer)->getJson('/api/tasks/board')->assertForbidden();
    });

    it('shows a project member the work on their project and not the rest', function (): void {
        $setup = taskScenario();
        $creative = $setup['scenario']->addMember(SystemRole::Creative);
        putOnProject($setup, $creative, SystemRole::Creative);

        $mine = openTask($setup, ['title' => 'On my project']);

        $otherClient = Client::factory()->create(['workspace_id' => $setup['scenario']->workspace->getKey()]);
        $otherProject = Project::factory()->forClient($otherClient)->create(['name' => 'Elsewhere']);

        test()->actingAs($setup['scenario']->owner)->postJson('/api/tasks', [
            'client_id' => (string) $otherClient->getKey(),
            'project_id' => (string) $otherProject->getKey(),
            'title' => 'Not mine',
        ])->assertCreated();

        $board = test()->actingAs($creative)->getJson('/api/tasks/board')->assertOk()->json('data');

        expect($board['workload']['total'])->toBe(1);

        $titles = [];

        foreach ($board['columns'] as $column) {
            foreach ($column['tasks'] as $task) {
                $titles[] = $task['title'];
            }
        }

        expect($titles)->toBe(['On my project']);
        expect($mine)->not->toBeEmpty();
    });

    it('shows internal work to anybody the permission admits', function (): void {
        $setup = taskScenario();
        $creative = $setup['scenario']->addMember(SystemRole::Creative);

        /*
         * No project and no client: the agency's own work. There is no client relationship to scope it by,
         * and withholding it would hide the board from the people who keep it.
         */
        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/tasks', ['title' => 'Write up the onboarding checklist'])
            ->assertCreated();

        $board = test()->actingAs($creative)->getJson('/api/tasks/board')->assertOk()->json('data');

        expect($board['workload']['total'])->toBe(1);
    });

    it('resolves "me" on the server rather than trusting the caller', function (): void {
        $setup = taskScenario();
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        putOnProject($setup, $buyer, SystemRole::MediaBuyer);

        openTask($setup, ['title' => 'Theirs', 'assignee_id' => (string) $buyer->getKey()]);
        openTask($setup, ['title' => 'Nobody else']);

        $board = test()->actingAs($buyer)
            ->getJson('/api/tasks/board?assignee_id=me')
            ->assertOk()
            ->json('data');

        /*
         * Resolved here, not in the browser. A front end sending its own idea of who the actor is would be
         * one session-restore defect away from showing somebody else's week.
         */
        expect($board['workload']['total'])->toBe(1);
    });

    it('narrows to the unassigned pile on request', function (): void {
        $setup = taskScenario();
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        putOnProject($setup, $buyer, SystemRole::MediaBuyer);

        openTask($setup, ['assignee_id' => (string) $buyer->getKey()]);
        openTask($setup, ['title' => 'Orphan']);

        $board = readBoard($setup, ['assignee_id' => 'unassigned']);

        expect($board['workload']['total'])->toBe(1);
        expect($board['workload']['unassigned'])->toBe(1);
    });
});

describe('the vocabulary', function (): void {
    it('publishes the columns, the legal moves and the scale', function (): void {
        $setup = taskScenario();

        $data = test()->actingAs($setup['scenario']->owner)
            ->getJson('/api/tasks/vocabulary')
            ->assertOk()
            ->json('data');

        /*
         * Fetched rather than hard-coded, so an interface cannot offer a move the server refuses. A front
         * end with its own copy of the transitions eventually disagrees with this one.
         */
        $blocked = array_values(array_filter(
            $data['states'],
            static fn (array $state): bool => $state['state'] === 'blocked',
        ))[0];

        expect($blocked['allowed_next'])->toBe(['in_progress', 'cancelled']);
        expect($blocked['needs_reason'])->toBeTrue();
        expect(array_column($data['priorities'], 'priority'))->toBe(['low', 'normal', 'high', 'urgent']);
        expect(array_column($data['horizons'], 'horizon'))
            ->toBe(['overdue', 'today', 'this_week', 'later', 'undated']);
    });
});

describe('who work can be given to', function (): void {
    /*
     * The list is served rather than assembled from the member directory, which needs `users.view` — a
     * permission somebody working a board has no reason to hold — and would offer colleagues the assignment then
     * refuses.
     */
    it('offers colleagues who can actually open the task', function (): void {
        $setup = taskScenario();
        $id = openTask($setup);
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        putOnProject($setup, $buyer, SystemRole::MediaBuyer);

        $people = test()->actingAs($setup['scenario']->owner)
            ->getJson("/api/tasks/{$id}/assignees")
            ->assertOk()
            ->json('data');

        expect(array_column($people, 'id'))->toContain((string) $buyer->getKey())
            /* Ids and names. A picker needs no email addresses, and nobody agreed to have theirs listed. */
            ->and(array_keys($people[0]))->toEqualCanonicalizing(['id', 'name']);
    });

    it('leaves out a colleague whose projects do not reach the client', function (): void {
        $setup = taskScenario();
        $id = openTask($setup);
        $elsewhere = $setup['scenario']->addMember(SystemRole::MediaBuyer);

        $people = test()->actingAs($setup['scenario']->owner)
            ->getJson("/api/tasks/{$id}/assignees")
            ->assertOk()
            ->json('data');

        expect(array_column($people, 'id'))->not->toContain((string) $elsewhere->getKey());
    });

    /*
     * The refusal this check exists for: without it the workload chart would describe work nobody is doing, and
     * the notification telling them would be suppressed by the same visibility rule — so nobody would find out.
     */
    it('refuses an assignee who could not open the task', function (): void {
        $setup = taskScenario();
        $id = openTask($setup);
        $elsewhere = $setup['scenario']->addMember(SystemRole::MediaBuyer);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/tasks/{$id}/assignee", ['assignee_id' => (string) $elsewhere->getKey()])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cannot_be_assigned');
    });

    /* The same rule at the moment work is created, not only when it is handed over afterwards. */
    it('refuses to open a task already in such a person’s name', function (): void {
        $setup = taskScenario();
        $elsewhere = $setup['scenario']->addMember(SystemRole::MediaBuyer);

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/tasks', [
                'title' => 'Work for somebody who cannot see it',
                'client_id' => (string) $setup['client']->getKey(),
                'project_id' => (string) $setup['project']->getKey(),
                'assignee_id' => (string) $elsewhere->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cannot_be_assigned');
    });

    /*
     * A `404` rather than a `403`, and that is the product's rule rather than an accident: a creative does hold
     * `tasks.manage` — they run their own work — but a task on a project they are not on is **not found**, the
     * same answer as a task that does not exist. Confirming it exists would itself be across the boundary.
     */
    it('does not exist for somebody whose projects do not reach the task', function (): void {
        $setup = taskScenario();
        $id = openTask($setup);

        test()->actingAs($setup['scenario']->addMember(SystemRole::Creative))
            ->getJson("/api/tasks/{$id}/assignees")
            ->assertNotFound();
    });
});
