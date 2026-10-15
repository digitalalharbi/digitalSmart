<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WorkspaceScenario;

/**
 * The Attention Center.
 *
 * One queue, unioned from the modules that already know something is wrong, with membership decided per
 * stream by permission. Most of what matters here is what the endpoint says about *itself*: which
 * streams it looked at, and which it could not read.
 */
/**
 * Open a task through the API, then back-date it.
 *
 * Through the API because that is the only writer: tasks have no Eloquent model, and inserting a row by
 * hand would skip the rules the board depends on. The due date is moved afterwards because the API will
 * not accept one in the past, which is correct of it.
 */
function overdueTask(WorkspaceScenario $scenario, string $title, ?string $assigneeId = null): string
{
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create();

    if ($assigneeId !== null) {
        /*
         * Through the project, because the domain refuses to hand work to somebody who cannot see it.
         * Writing `assignee_id` straight into the row would pass this test by skipping the rule it
         * depends on.
         */
        test()->actingAs($scenario->owner)
            ->postJson("/api/projects/{$project->getKey()}/members", [
                'user_id' => $assigneeId,
                'role' => SystemRole::ProjectManager->value,
            ])
            ->assertSuccessful();
    }

    /** @var string $id */
    $id = test()->actingAs($scenario->owner)
        ->postJson('/api/tasks', array_filter([
            'client_id' => (string) $client->getKey(),
            'project_id' => (string) $project->getKey(),
            'title' => $title,
            'priority' => 'normal',
            'assignee_id' => $assigneeId,
        ]))
        ->assertCreated()
        ->json('data.id');

    DB::table('tasks')->where('id', $id)->update(['due_on' => now()->subDays(5)->toDateString()]);

    return $id;
}

it('raises an overdue task into the queue with who is holding it', function (): void {
    $scenario = workspaceScenario();
    overdueTask($scenario, 'Ship the Ramadan creative', $scenario->owner->getKey());

    $this->actingAs($scenario->owner)->getJson('/api/attention?stream[]=tasks')
        ->assertOk()
        ->assertJsonPath('data.0.stream', 'tasks')
        ->assertJsonPath('data.0.severity', 'needs_attention')
        ->assertJsonPath('data.0.message', 'Overdue: Ship the Ramadan creative')
        ->assertJsonPath('data.0.owner', $scenario->owner->name);
});

/*
 * Null and absent are different facts. A task nobody holds is the one most likely to rot, and the
 * interface prints that; a budget has no assignee at all, and printing "Unassigned" against one would
 * invent a gap that never existed.
 */
it('says nobody holds an unassigned task rather than leaving a gap', function (): void {
    $scenario = workspaceScenario();
    overdueTask($scenario, 'Nobody has this');

    $this->actingAs($scenario->owner)->getJson('/api/attention?stream[]=tasks')
        ->assertOk()
        ->assertJsonPath('data.0.owner', null);
});

it('publishes which streams it actually looked at', function (): void {
    $scenario = workspaceScenario();

    $covered = $this->actingAs($scenario->owner)->getJson('/api/attention')
        ->assertOk()
        ->json('meta.covered');

    expect(array_column($covered, 'stream'))
        ->toContain('campaigns', 'budgets', 'tracking', 'approvals', 'requests', 'tasks');
});

/*
 * "Nothing needs attention" means something different to a creative lead than to a finance manager, and
 * an empty list without its scope is a reassurance the product has not earned.
 */
it('narrows the streams to what the reader may see', function (): void {
    $scenario = workspaceScenario();
    $creative = $scenario->addMember(SystemRole::Creative);

    $covered = $this->actingAs($creative)->getJson('/api/attention')
        ->assertOk()
        ->json('meta.covered');

    $streams = array_column($covered, 'stream');

    /*
     * A creative does hold `approvals.view` — they ask for publishes and watch their own go through — so
     * that stream is theirs. Budgets and the client intake queue are not: the first is finance, and the
     * second carries every client's commercial asks and the agency's reasoning about them.
     */
    expect($streams)->not->toContain('budgets')
        ->and($streams)->not->toContain('requests')
        ->and($streams)->toContain('approvals', 'tasks');
});

it('answers a reader whose role covers nothing with an empty scope, not a refusal', function (): void {
    $scenario = workspaceScenario();
    $client = $scenario->addMember(SystemRole::ClientViewer);

    $this->actingAs($client)->getJson('/api/attention')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});

it('counts every severity, including the ones with nobody in them', function (): void {
    $scenario = workspaceScenario();
    overdueTask($scenario, 'One thing');

    $this->actingAs($scenario->owner)->getJson('/api/attention?stream[]=tasks')
        ->assertOk()
        ->assertJsonPath('meta.severity_counts.needs_attention', 1)
        ->assertJsonPath('meta.severity_counts.critical', 0)
        ->assertJsonPath('meta.severity_counts.watch', 0);
});

it('narrows to one stream when asked, and refuses a stream it does not know', function (): void {
    $scenario = workspaceScenario();
    overdueTask($scenario, 'Only task');

    $covered = $this->actingAs($scenario->owner)->getJson('/api/attention?stream[]=tasks')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->json('meta.covered');

    expect(array_column($covered, 'stream'))->toBe(['tasks']);

    $this->actingAs($scenario->owner)->getJson('/api/attention?stream[]=weather')
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonPath('code', 'invalid_stream');
});

it('sends a reader to the surface that can settle the item', function (): void {
    $scenario = workspaceScenario();
    overdueTask($scenario, 'Go somewhere');

    $this->actingAs($scenario->owner)->getJson('/api/attention?stream[]=tasks')
        ->assertOk()
        ->assertJsonPath('data.0.action.path', '/tasks');
});

/*
 * A workspace with no work in it still has one finding — measurement raises "nothing is being measured"
 * the moment a workspace exists, which is correct and is why this asserts on the task stream rather than
 * on an empty whole.
 */
it('reports nothing waiting in a stream as an empty list rather than as an error', function (): void {
    $scenario = workspaceScenario();

    $this->actingAs($scenario->owner)->getJson('/api/attention?stream[]=tasks')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.failed', []);
});

/*
 * And the finding it does have is a real one, raised by measurement rather than invented here: a
 * workspace measuring nothing is a workspace whose reports will come out quietly empty.
 */
it('surfaces a workspace that is measuring nothing at all', function (): void {
    $scenario = workspaceScenario();

    $this->actingAs($scenario->owner)->getJson('/api/attention?stream[]=tracking')
        ->assertOk()
        ->assertJsonPath('data.0.stream', 'tracking')
        ->assertJsonPath('data.0.action.path', '/tracking');
});

it('narrows to the projects and clients the reader is a member of, and says it is narrowed', function (): void {
    $scenario = WorkspaceScenario::create();
    $mine = Project::factory()->forClient(Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]))->create();
    $theirs = Project::factory()->forClient(Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]))->create();

    foreach ([[$mine, 'My overdue'], [$theirs, 'Their overdue']] as [$project, $title]) {
        $id = $this->actingAs($scenario->owner)->postJson('/api/tasks', [
            'client_id' => (string) $project->getAttribute('client_id'), 'project_id' => (string) $project->getKey(),
            'title' => $title, 'priority' => 'normal', 'due_on' => now()->addDays(3)->toDateString(),
        ])->assertSuccessful()->json('data.id');
        DB::table('tasks')->where('id', $id)->update(['due_on' => now()->subDays(3)->toDateString()]);
    }

    $buyer = $scenario->addMember(SystemRole::ProjectManager);
    DB::table('project_members')->insert([
        'id' => (string) Illuminate\Support\Str::uuid(), 'project_id' => $mine->getKey(), 'user_id' => $buyer->getKey(),
        'role_id' => $scenario->role(SystemRole::ProjectManager)->getKey(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $messages = fn (string $audience): array => array_column(
        $this->actingAs($scenario->owner)->getJson('/api/attention?audience='.$audience)->assertOk()->json('data'),
        'message',
    );

    // The owner sees the whole agency; narrowing to "my projects" for an owner who is on none is empty.
    expect(implode('|', $messages('all')))->toContain('My overdue')->toContain('Their overdue')
        ->and($messages('my_projects'))->toBe([])
        ->and($this->actingAs($scenario->owner)->getJson('/api/attention?audience=my_projects')->json('meta.audience'))->toBe('my_projects');

    $own = $this->actingAs($buyer)->getJson('/api/attention?audience=my_projects')->assertOk()->json('data');
    $ownText = implode('|', array_column($own, 'message'));

    expect($ownText)->toContain('My overdue');
    expect(str_contains($ownText, 'Their overdue'))->toBeFalse();
});
