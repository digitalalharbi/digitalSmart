<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Workspace\MembershipStatus;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
|
| A notification is a **disclosure**. "Raise the Ramadan daily budget is waiting for your decision" names a
| client, a campaign and an intention, so the rule this file mostly tests is: a person may be told about
| something only if they could have read it. Permission, plus project visibility, plus active membership.
|
| The second rule is about noise. Nobody is told what they just did; a request for a spend change reaches only
| the people who can actually approve one; and the count that leads the bell is "waiting on you" rather than
| "unread", because the second is a measure of how long somebody has been away.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project} */
function notificationScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project];
}

/** @param array{scenario: WorkspaceScenario, client: Client, project: Project} $setup */
function onProject(array $setup, User $user, SystemRole $role): void
{
    ProjectMember::query()->create([
        'project_id' => $setup['project']->getKey(),
        'user_id' => $user->getKey(),
        'role_id' => $setup['scenario']->role($role)->getKey(),
    ]);
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function feedFor(array $setup, User $user, array $query = []): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($user)
        ->getJson('/api/notifications'.($query === [] ? '' : '?'.http_build_query($query)))
        ->assertOk()
        ->json('data');
}

/**
 * Raise an approval request as somebody, and return its body.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function askAs(array $setup, User $as, array $overrides = []): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($as)
        ->postJson('/api/approvals', [
            'kind' => 'spend_change',
            'title' => 'Raise the Ramadan daily budget',
            'rationale' => 'Cost per purchase has held under target for nine days.',
            'proposal' => ['to_daily_minor' => 80_000, 'currency' => 'SAR'],
            'client_id' => (string) $setup['client']->getKey(),
            'project_id' => (string) $setup['project']->getKey(),
            ...$overrides,
        ])
        ->assertCreated()
        ->json('data');
}

describe('who gets told', function (): void {
    it('tells the person a task was assigned to, and not the person who assigned it', function (): void {
        $setup = notificationScenario();
        $owner = $setup['scenario']->owner;
        $creative = $setup['scenario']->addMember(SystemRole::Creative);
        onProject($setup, $creative, SystemRole::Creative);

        $taskId = test()->actingAs($owner)->postJson('/api/tasks', [
            'title' => 'Rebuild the audience lists',
            'client_id' => (string) $setup['client']->getKey(),
            'project_id' => (string) $setup['project']->getKey(),
        ])->assertCreated()->json('data.id');

        test()->actingAs($owner)
            ->postJson("/api/tasks/{$taskId}/assignee", ['assignee_id' => (string) $creative->getKey()])
            ->assertOk();

        expect(feedFor($setup, $creative)['digest']['waiting'])->toBe(1);
        /*
         * The owner is told nothing. A notification about your own action is noise, and noise is what teaches
         * people to stop reading the bell.
         */
        expect(feedFor($setup, $owner)['digest']['total'])->toBe(0);
    });

    it('tells nobody when work is assigned to the person doing the assigning', function (): void {
        $setup = notificationScenario();
        $owner = $setup['scenario']->owner;

        $taskId = test()->actingAs($owner)->postJson('/api/tasks', [
            'title' => 'Write the monthly deck',
        ])->assertCreated()->json('data.id');

        test()->actingAs($owner)
            ->postJson("/api/tasks/{$taskId}/assignee", ['assignee_id' => (string) $owner->getKey()])
            ->assertOk();

        expect(feedFor($setup, $owner)['digest']['total'])->toBe(0);
    });

    it('tells nobody when work is unassigned', function (): void {
        $setup = notificationScenario();
        $owner = $setup['scenario']->owner;
        $creative = $setup['scenario']->addMember(SystemRole::Creative);
        onProject($setup, $creative, SystemRole::Creative);

        $taskId = test()->actingAs($owner)->postJson('/api/tasks', [
            'title' => 'Something',
            'client_id' => (string) $setup['client']->getKey(),
            'project_id' => (string) $setup['project']->getKey(),
            'assignee_id' => (string) $creative->getKey(),
        ])->assertCreated()->json('data.id');

        // One notification from the assignment at creation, which is the baseline this test measures against.
        expect(feedFor($setup, $creative)['digest']['total'])->toBe(1);

        test()->actingAs($owner)->postJson("/api/tasks/{$taskId}/assignee", [])->assertOk();

        /*
         * Still one. There is no new owner to tell, and notifying the person it was taken from would be a
         * complaint rather than a task.
         */
        expect(feedFor($setup, $creative)['digest']['total'])->toBe(1);
    });

    it('tells only people who could actually approve a spend change', function (): void {
        $setup = notificationScenario();
        $manager = $setup['scenario']->addMember(SystemRole::ProjectManager);
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        onProject($setup, $buyer, SystemRole::MediaBuyer);

        askAs($setup, $buyer);

        /*
         * The manager holds `approvals.decide` *and* `budgets.approve`, which this kind requires. The buyer
         * asked, so they are the actor and are told nothing — and could not decide it anyway. Telling five
         * people when three of them cannot approve it is how a bell comes to be ignored.
         */
        expect(feedFor($setup, $manager)['digest']['waiting'])->toBe(1);
        expect(feedFor($setup, $buyer)['digest']['total'])->toBe(0);
    });

    it('does not tell somebody who holds the module permission but not the one the kind needs', function (): void {
        $setup = notificationScenario();
        $owner = $setup['scenario']->owner;
        $creative = $setup['scenario']->addMember(SystemRole::Creative);
        onProject($setup, $creative, SystemRole::Creative);

        /*
         * Granted `approvals.decide` by hand and still not told, because a spend change also needs
         * `budgets.approve`. Otherwise a notification would invite somebody to a decision the server refuses.
         */
        $role = $setup['scenario']->role(SystemRole::Creative);
        $permission = DB::table('permissions')->where('slug', 'approvals.decide')->value('id');
        DB::table('permission_role')->insert(['role_id' => $role->getKey(), 'permission_id' => $permission]);

        askAs($setup, $owner);

        expect(feedFor($setup, $creative)['digest']['total'])->toBe(0);
    });

    it('does not tell somebody who cannot see the project it is about', function (): void {
        $setup = notificationScenario();
        $owner = $setup['scenario']->owner;

        /*
         * A manager has `projects.view_all`, so they see everything. A buyer does not, and this one is on no
         * project — so an approval about a client they have no access to must not arrive, title and all.
         */
        $stranger = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        $permission = DB::table('permissions')->where('slug', 'approvals.decide')->value('id');
        $budgets = DB::table('permissions')->where('slug', 'budgets.approve')->value('id');
        $role = $setup['scenario']->role(SystemRole::MediaBuyer);
        DB::table('permission_role')->insert([
            ['role_id' => $role->getKey(), 'permission_id' => $permission],
            ['role_id' => $role->getKey(), 'permission_id' => $budgets],
        ]);

        askAs($setup, $owner);

        // Both permissions, no visibility. A notification is a disclosure, and this is the disclosure refused.
        expect(feedFor($setup, $stranger)['digest']['total'])->toBe(0);
    });

    it('does not tell a suspended member', function (): void {
        $setup = notificationScenario();
        $owner = $setup['scenario']->owner;
        $manager = $setup['scenario']->addMember(SystemRole::ProjectManager);

        DB::table('workspace_members')
            ->where('workspace_id', $setup['scenario']->workspace->getKey())
            ->where('user_id', $manager->getKey())
            ->update(['status' => MembershipStatus::Suspended->value]);

        askAs($setup, $owner);

        /*
         * Somebody whose access was taken away must not keep receiving a running commentary on the client they
         * were removed from.
         */
        expect(DB::table('notifications')->where('user_id', $manager->getKey())->count())->toBe(0);
    });

    it('tells the requester when their request is answered, with the reason', function (): void {
        $setup = notificationScenario();
        $owner = $setup['scenario']->owner;
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        onProject($setup, $buyer, SystemRole::MediaBuyer);

        $request = askAs($setup, $buyer);

        test()->actingAs($owner)->postJson("/api/approvals/{$request['id']}/decision", [
            'outcome' => 'declined',
            'fingerprint' => $request['fingerprint'],
            'reason' => 'The client has not signed the revised scope.',
        ])->assertOk();

        $feed = feedFor($setup, $buyer);
        $answered = $feed['notifications'][0];

        expect($answered['kind'])->toBe('approval_decided');
        /*
         * The reason is in the body. A notification saying "declined" with the reason a click away is a
         * notification that generates a second conversation.
         */
        expect($answered['body'])->toContain('has not signed the revised scope');
        // Being told an answer is not a task, so it is unread without being "waiting".
        expect($feed['digest']['waiting'])->toBe(0);
        expect($feed['digest']['unread'])->toBe(1);
    });

    it('tells the decider when their approved change was carried out', function (): void {
        $setup = notificationScenario();
        $owner = $setup['scenario']->owner;
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        onProject($setup, $buyer, SystemRole::MediaBuyer);

        $request = askAs($setup, $buyer);

        test()->actingAs($owner)->postJson("/api/approvals/{$request['id']}/decision", [
            'outcome' => 'approved',
            'fingerprint' => $request['fingerprint'],
        ])->assertOk();

        test()->actingAs($buyer)->postJson("/api/approvals/{$request['id']}/enactment", [
            'enactment' => 'confirmed',
            'note' => 'Raised in the platform at 10:40.',
        ])->assertOk();

        /*
         * The loop this module exists to close: the person who approved a change to live spend learns whether it
         * actually happened.
         */
        $feed = feedFor($setup, $owner);
        $kinds = array_column($feed['notifications'], 'kind');

        expect($kinds)->toContain('approval_enacted');
    });
});

describe('reading them', function (): void {
    it('leads with what is waiting rather than with what is unread', function (): void {
        $setup = notificationScenario();
        $owner = $setup['scenario']->owner;
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        onProject($setup, $buyer, SystemRole::MediaBuyer);

        $request = askAs($setup, $buyer);

        test()->actingAs($owner)->postJson("/api/approvals/{$request['id']}/decision", [
            'outcome' => 'approved',
            'fingerprint' => $request['fingerprint'],
        ])->assertOk();

        $feed = feedFor($setup, $buyer);

        /*
         * One unread notification, and nothing waiting: being told an answer is not a task. "Twelve unread"
         * measures how long somebody has been away; "waiting" is a to-do list.
         */
        expect($feed['digest']['unread'])->toBe(1);
        expect($feed['digest']['waiting'])->toBe(0);
    });

    it('marks one read without touching the others', function (): void {
        $setup = notificationScenario();
        $owner = $setup['scenario']->owner;
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        onProject($setup, $buyer, SystemRole::MediaBuyer);

        askAs($setup, $buyer, ['title' => 'First request']);
        askAs($setup, $buyer, ['title' => 'Second request']);

        $feed = feedFor($setup, $owner);
        expect($feed['digest']['unread'])->toBe(2);

        $first = $feed['notifications'][0]['id'];

        $after = test()->actingAs($owner)
            ->postJson("/api/notifications/{$first}/read")
            ->assertOk()
            ->json('data');

        /*
         * The whole feed comes back, not just the row. Marking one read changes the counts above the list, and a
         * client decrementing them itself would be a second implementation of arithmetic that does not know
         * which kinds count as waiting.
         */
        expect($after['digest']['unread'])->toBe(1);
        expect($after['digest']['waiting'])->toBe(1);
    });

    it('refuses to mark somebody else\'s notification read, and will not confirm it exists', function (): void {
        $setup = notificationScenario();
        $owner = $setup['scenario']->owner;
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        onProject($setup, $buyer, SystemRole::MediaBuyer);

        askAs($setup, $buyer);

        $mine = feedFor($setup, $owner)['notifications'][0]['id'];

        /*
         * Not found rather than forbidden. "Forbidden" would confirm the notification exists and belongs to
         * somebody else, which is a fact the asker has no business learning.
         */
        test()->actingAs($buyer)->postJson("/api/notifications/{$mine}/read")->assertNotFound();

        expect(feedFor($setup, $owner)['digest']['unread'])->toBe(1);
    });

    it('clears everything and says how many that was', function (): void {
        $setup = notificationScenario();
        $owner = $setup['scenario']->owner;
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        onProject($setup, $buyer, SystemRole::MediaBuyer);

        askAs($setup, $buyer, ['title' => 'One']);
        askAs($setup, $buyer, ['title' => 'Two']);

        $response = test()->actingAs($owner)->postJson('/api/notifications/read-all')->assertOk();

        expect($response->json('meta.cleared'))->toBe(2);
        expect($response->json('data.digest.unread'))->toBe(0);
    });

    it('keeps the first read time when something is marked read twice', function (): void {
        $setup = notificationScenario();
        $owner = $setup['scenario']->owner;
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        onProject($setup, $buyer, SystemRole::MediaBuyer);

        askAs($setup, $buyer);

        $id = feedFor($setup, $owner)['notifications'][0]['id'];

        test()->actingAs($owner)->postJson("/api/notifications/{$id}/read")->assertOk();
        $first = DB::table('notifications')->where('id', $id)->value('read_at');

        test()->actingAs($owner)->postJson("/api/notifications/{$id}/read")->assertOk();

        // Marking something read twice is not an error, and must not move the time it was first read.
        expect(DB::table('notifications')->where('id', $id)->value('read_at'))->toBe($first);
    });

    it('narrows to unread on request', function (): void {
        $setup = notificationScenario();
        $owner = $setup['scenario']->owner;
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        onProject($setup, $buyer, SystemRole::MediaBuyer);

        askAs($setup, $buyer, ['title' => 'One']);
        askAs($setup, $buyer, ['title' => 'Two']);

        $id = feedFor($setup, $owner)['notifications'][0]['id'];
        test()->actingAs($owner)->postJson("/api/notifications/{$id}/read")->assertOk();

        expect(feedFor($setup, $owner, ['unread' => '1'])['notifications'])->toHaveCount(1);
        // The counts are over everything regardless of the filter: a badge is not a view.
        expect(feedFor($setup, $owner, ['unread' => '1'])['digest']['total'])->toBe(2);
    });

    it('says the thing it points at has been deleted rather than linking to nothing', function (): void {
        $setup = notificationScenario();
        $owner = $setup['scenario']->owner;
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        onProject($setup, $buyer, SystemRole::MediaBuyer);

        $request = askAs($setup, $buyer);

        expect(feedFor($setup, $owner)['notifications'][0]['subject']['exists'])->toBeTrue();

        DB::table('approval_requests')->where('id', $request['id'])->delete();

        $after = feedFor($setup, $owner)['notifications'][0];

        /*
         * There is no foreign key behind a notification's subject, so this case is real. The sentence that was
         * announced stays exactly as it was: it is a record of what was said, not a template re-rendered later.
         */
        expect($after['subject']['exists'])->toBeFalse();
        expect($after['title'])->toBe('Raise the Ramadan daily budget');
    });

    it('reports whether this session is being pushed to', function (): void {
        $setup = notificationScenario();

        /*
         * Null in the test environment, so live updates are off and the feed says so. A page that implied it was
         * live while polling is a page where somebody waits for an update that never comes.
         */
        expect(feedFor($setup, $setup['scenario']->owner)['is_live'])->toBeFalse();
    });

    it('keeps one workspace\'s notifications out of another', function (): void {
        $setup = notificationScenario();
        $owner = $setup['scenario']->owner;
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        onProject($setup, $buyer, SystemRole::MediaBuyer);

        askAs($setup, $buyer);

        $other = WorkspaceScenario::create();

        // Every read is workspace-scoped first, because a person may be a member of several.
        expect(feedFor(['scenario' => $other, 'client' => $setup['client'], 'project' => $setup['project']], $other->owner)['digest']['total'])->toBe(0);
    });
});

it('tells somebody about work opened already in their name', function (): void {
    $setup = notificationScenario();
    $owner = $setup['scenario']->owner;
    $creative = $setup['scenario']->addMember(SystemRole::Creative);
    onProject($setup, $creative, SystemRole::Creative);

    /*
     * Opening a task already assigned is the commonest way work gets handed over, so it has to announce as well
     * as a later re-assignment — otherwise the most frequent assignment is the one nobody is told about.
     */
    test()->actingAs($owner)->postJson('/api/tasks', [
        'title' => 'Second pass on the story copy',
        'client_id' => (string) $setup['client']->getKey(),
        'project_id' => (string) $setup['project']->getKey(),
        'assignee_id' => (string) $creative->getKey(),
    ])->assertCreated();

    $feed = feedFor($setup, $creative);

    expect($feed['digest']['waiting'])->toBe(1);
    expect($feed['notifications'][0]['title'])->toBe('Second pass on the story copy');
    expect($feed['notifications'][0]['kind'])->toBe('task_assigned');
});
