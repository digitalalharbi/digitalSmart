<?php

declare(strict_types=1);

use App\Application\Operations\Command\DecideApproval;
use App\Application\Operations\Command\OpenTask;
use App\Application\Operations\Command\RequestApproval;
use App\Application\Operations\DTO\ApprovalInput;
use App\Application\Operations\DTO\TaskInput;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Domain\Identity\UserId;
use App\Domain\Operations\ApprovalKind;
use App\Domain\Operations\ApprovalState;
use App\Domain\Operations\TaskPriority;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Query budgets
|--------------------------------------------------------------------------
|
| These assert **scale invariance**, not a ceiling. A fixed maximum is a number somebody raises the first time it
| fails; "the query count must not grow with the number of rows" is the actual property, it needs no magic
| figure, and it is exactly the defect worth catching: an N+1 is invisible on a developer's three seeded rows and
| takes a page down on a client with four hundred.
|
| A small tolerance is allowed because a paginated endpoint legitimately runs one extra query when a second page
| exists. It is a tolerance on the slope, not on the count.
|
*/

/** Queries run while serving one request, counted on the connection rather than guessed at. */
function queriesFor(callable $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $request();

        return count(DB::getRawQueryLog());
    } finally {
        DB::disableQueryLog();
    }
}

it('reads the client list in a count that does not grow with the clients', function (): void {
    $scenario = WorkspaceScenario::create();

    Client::factory()->count(2)->create(['workspace_id' => $scenario->workspace->getKey()]);

    $few = queriesFor(fn () => test()->actingAs($scenario->owner)
        ->getJson('/api/clients?per_page=100')
        ->assertOk());

    Client::factory()->count(40)->create(['workspace_id' => $scenario->workspace->getKey()]);

    $many = queriesFor(fn () => test()->actingAs($scenario->owner)
        ->getJson('/api/clients?per_page=100')
        ->assertOk());

    /*
     * Twenty times the rows, the same work. If this ever fails, something started loading a relation per row —
     * which is the one performance defect that cannot be seen on a seeded database.
     */
    expect($many)->toBeLessThanOrEqual($few + 1);
});

it('reads the approvals queue in a count that does not grow with the queue', function (): void {
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'client_id' => $client->getKey(),
    ]);

    test()->actingAs($scenario->owner);

    /*
     * The use cases are called directly to build the queue, and an actor is bound the way the request middleware
     * binds one — a seeder that bypassed the guard would exercise a path no live caller takes.
     */
    app(ActorContextBinder::class)->bind(
        new UserId((string) $scenario->owner->getKey()),
        new WorkspaceId((string) $scenario->workspace->getKey()),
    );

    $ask = app(RequestApproval::class);
    $decide = app(DecideApproval::class);

    $raise = function (int $count) use ($ask, $decide, $client, $project): void {
        for ($i = 0; $i < $count; $i++) {
            $request = $ask(new ApprovalInput(
                kind: ApprovalKind::SpendChange,
                title: 'Raise the daily budget '.$i,
                rationale: 'Cost per purchase has held under target.',
                proposal: ['to_daily_minor' => 80_000 + $i, 'currency' => 'SAR'],
                clientId: (string) $client->getKey(),
                projectId: (string) $project->getKey(),
            ));

            /* Half decided, so both queues in the response have rows to render. */
            if ($i % 2 === 0) {
                $decide($request->id, ApprovalState::Approved, $request->fingerprint);
            }
        }
    };

    $raise(4);

    $few = queriesFor(fn () => test()->getJson('/api/approvals/queue')->assertOk());

    $raise(40);

    $many = queriesFor(fn () => test()->getJson('/api/approvals/queue')->assertOk());

    /*
     * The queue joins clients, projects and the people who asked and decided, for three lists and a summary.
     * Doing that per row is the obvious implementation and the wrong one.
     */
    expect($many)->toBeLessThanOrEqual($few + 1);
});

it('reads the work board in a count that does not grow with the board', function (): void {
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'client_id' => $client->getKey(),
    ]);

    test()->actingAs($scenario->owner);

    app(ActorContextBinder::class)->bind(
        new UserId((string) $scenario->owner->getKey()),
        new WorkspaceId((string) $scenario->workspace->getKey()),
    );

    $open = app(OpenTask::class);

    $fill = function (int $count) use ($open, $client, $project, $scenario): void {
        for ($i = 0; $i < $count; $i++) {
            $open(new TaskInput(
                title: 'Rebuild the audience lists '.$i,
                priority: TaskPriority::Normal,
                description: 'Queued for the budget test.',
                clientId: (string) $client->getKey(),
                projectId: (string) $project->getKey(),
                /* Assigned, so the board has a person to resolve per card — the obvious place an N+1 appears. */
                assigneeId: (string) $scenario->owner->getKey(),
                dueOn: new DateTimeImmutable('tomorrow'),
            ));
        }
    };

    $fill(4);

    $few = queriesFor(fn () => test()->getJson('/api/tasks/board')->assertOk());

    $fill(40);

    $many = queriesFor(fn () => test()->getJson('/api/tasks/board')->assertOk());

    /*
     * Eleven times the cards, the same work. The board resolves a client, a project and an assignee per card
     * and publishes four summary figures over all of them, which is precisely the shape that invites a query
     * per row.
     */
    expect($many)->toBeLessThanOrEqual($few + 1);
});
