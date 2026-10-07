<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Query budgets
|--------------------------------------------------------------------------
|
| These tests exist because this module's reads were measurably slow and nothing in the suite noticed. A detail
| read ran thirty-six queries: the spawned-task lookup went through the task board, which is six queries by
| design — one per column — and the request was re-read after every write.
|
| A budget is asserted rather than a duration. A duration test fails on a loaded machine and passes on a fast
| one, which teaches people to re-run it; a query count is deterministic, and the number it protects is the one
| that actually grew. The budgets are deliberately a little above what the code does, so a refactor that adds one
| query is allowed and one that adds a join per message is not.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project} */
function queryBudgetScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    $viewer = $scenario->addMember(SystemRole::ClientViewer);

    ProjectMember::query()->create([
        'project_id' => $project->getKey(),
        'user_id' => $viewer->getKey(),
        'role_id' => $scenario->role(SystemRole::ClientViewer)->getKey(),
    ]);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project];
}

/**
 * Count the queries one closure runs.
 *
 * Prefixed, like the scenario helper below it. Pest shares one global function namespace across every test file,
 * so a plain `countQueries` or `budgetScenario` collides with another file's helper — and the collision is a fatal
 * error that appears only in the full suite, after the file has passed on its own.
 *
 * @return array{count: int, queries: list<string>}
 */
function countOperationsQueries(callable $work): array
{
    /** @var list<string> $queries */
    $queries = [];

    DB::flushQueryLog();
    DB::enableQueryLog();

    $work();

    foreach (DB::getRawQueryLog() as $entry) {
        $queries[] = (string) ($entry['raw_query'] ?? '');
    }

    DB::disableQueryLog();

    return ['count' => count($queries), 'queries' => $queries];
}

it('reads one client request within a query budget that does not grow with its conversation', function (): void {
    $setup = queryBudgetScenario();
    $owner = $setup['scenario']->owner;

    $id = test()->actingAs($owner)->postJson('/api/client-requests', [
        'client_id' => (string) $setup['client']->getKey(),
        'project_id' => (string) $setup['project']->getKey(),
        'kind' => 'change',
        'title' => 'Pause the retargeting over Eid',
    ])->assertCreated()->json('data.id');

    // Ten messages and three tasks. A per-row query would show up here and nowhere else.
    foreach (range(1, 10) as $index) {
        test()->actingAs($owner)->postJson("/api/client-requests/{$id}/messages", [
            'audience' => $index % 2 === 0 ? 'internal' : 'client',
            'body' => 'Message '.$index,
        ])->assertCreated();
    }

    foreach (range(1, 3) as $index) {
        test()->actingAs($owner)->postJson('/api/tasks', [
            'title' => 'Task '.$index,
            'client_id' => (string) $setup['client']->getKey(),
            'project_id' => (string) $setup['project']->getKey(),
            'subject_type' => 'client_request',
            'subject_id' => $id,
        ])->assertCreated();
    }

    $measured = countOperationsQueries(function () use ($owner, $id): void {
        test()->actingAs($owner)->getJson("/api/client-requests/{$id}")->assertOk();
    });

    /*
     * Four reads of the module's own data — the request, its messages, its tasks, and the subject labels those
     * tasks point at — plus the session and permission reads every authenticated request makes. Twelve is the
     * ceiling; the point is that it is a constant, not that it is four.
     */
    expect($measured['count'])->toBeLessThanOrEqual(12);

    // And no query mentions a single message or task id, which is what a per-row lookup would look like.
    $perRow = array_filter(
        $measured['queries'],
        static fn (string $query): bool => str_contains($query, 'client_request_messages')
            && str_contains($query, 'limit 1'),
    );

    expect($perRow)->toBe([]);
});

it('reads the intake queue in a number of queries that does not grow with the work on it', function (): void {
    $setup = queryBudgetScenario();
    $owner = $setup['scenario']->owner;

    foreach (range(1, 12) as $index) {
        $id = test()->actingAs($owner)->postJson('/api/client-requests', [
            'client_id' => (string) $setup['client']->getKey(),
            'project_id' => (string) $setup['project']->getKey(),
            'kind' => 'change',
            'title' => 'Request '.$index,
        ])->assertCreated()->json('data.id');

        /*
         * Each with a message, which is the trap: a board that loaded conversations would run a query per card
         * and look fine with one request on it.
         */
        test()->actingAs($owner)->postJson("/api/client-requests/{$id}/messages", [
            'audience' => 'client',
            'body' => 'Noted.',
        ])->assertCreated();
    }

    $measured = countOperationsQueries(function () use ($owner): void {
        test()->actingAs($owner)->getJson('/api/client-requests/queue')->assertOk();
    });

    /*
     * One query per column — seven states — plus the aggregate, plus the session reads. Cards deliberately carry
     * no conversation and no tasks, which is what keeps this a constant rather than one query per card.
     */
    expect($measured['count'])->toBeLessThanOrEqual(16);
});

it('reads the task board in a number of queries that does not grow with the tasks on it', function (): void {
    $setup = queryBudgetScenario();
    $owner = $setup['scenario']->owner;

    foreach (range(1, 15) as $index) {
        test()->actingAs($owner)->postJson('/api/tasks', [
            'title' => 'Task '.$index,
            'client_id' => (string) $setup['client']->getKey(),
            'project_id' => (string) $setup['project']->getKey(),
        ])->assertCreated();
    }

    $measured = countOperationsQueries(function () use ($owner): void {
        test()->actingAs($owner)->getJson('/api/tasks/board')->assertOk();
    });

    // Six columns, the cap check, the workload, the per-person load, and the session reads.
    expect($measured['count'])->toBeLessThanOrEqual(18);
});

it('reads the approval queue in a number of queries that does not grow with the queue', function (): void {
    $setup = queryBudgetScenario();
    $owner = $setup['scenario']->owner;

    foreach (range(1, 10) as $index) {
        test()->actingAs($owner)->postJson('/api/approvals', [
            'kind' => 'spend_change',
            'title' => 'Request '.$index,
            'rationale' => 'Because the numbers hold.',
            'proposal' => ['to_daily_minor' => 80_000],
            'client_id' => (string) $setup['client']->getKey(),
            'project_id' => (string) $setup['project']->getKey(),
        ])->assertCreated();
    }

    $measured = countOperationsQueries(function () use ($owner): void {
        test()->actingAs($owner)->getJson('/api/approvals/queue')->assertOk();
    });

    // Three lists and one aggregate, plus the session reads.
    expect($measured['count'])->toBeLessThanOrEqual(12);
});
