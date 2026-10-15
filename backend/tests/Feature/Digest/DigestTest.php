<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

function digestWorld(): SyncedWorkspace
{
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $owner = $w->scenario->owner;

    /* Something to say: a task already past its date. */
    $project = $w->project(Provider::Meta);
    $id = test()->actingAs($owner)->postJson('/api/tasks', [
        'client_id' => (string) $project->getAttribute('client_id'), 'project_id' => (string) $project->getKey(),
        'title' => 'Overdue thing', 'priority' => 'normal',
    ])->json('data.id');
    DB::table('tasks')->where('id', $id)->update(['due_on' => now()->subDays(4)->toDateString()]);

    return $w;
}

it('writes one digest per person instead of many alerts, and reports what it did', function (): void {
    $w = digestWorld();

    $this->artisan('digests:build weekly_projects')->assertSuccessful();

    $mine = test()->actingAs($w->scenario->owner)->getJson('/api/digests')->assertOk()->json('data');

    expect($mine)->toHaveCount(1)
        ->and($mine[0]['kind'])->toBe('weekly_projects')
        ->and($mine[0]['total'])->toBeGreaterThan(0)
        ->and($mine[0]['summary']['by_stream'])->toHaveKey('tasks');
});

it('is idempotent: the same morning twice is one digest', function (): void {
    digestWorld();

    $this->artisan('digests:build weekly_projects')->assertSuccessful();
    $this->artisan('digests:build weekly_projects')->assertSuccessful();

    expect(DB::table('digests')->where('kind', 'weekly_projects')->count())->toBe(DB::table('digests')->distinct()->count('user_id'));
});

it('writes nothing for a person with nothing to say', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    $this->artisan('digests:build daily_budget')->assertSuccessful();

    expect(DB::table('digests')->count())->toBe(0)
        ->and($w->workspaceId())->not->toBe('');
});

/* A digest is built as the person it is for, so it can never be a side door around visibility. */
it('shows a person only what their own scope would show them', function (): void {
    $w = digestWorld();
    $buyer = $w->scenario->addMember(SystemRole::MediaBuyer);

    $this->artisan('digests:build weekly_projects')->assertSuccessful();

    test()->actingAs($buyer)->getJson('/api/digests')->assertOk()->assertJsonCount(0, 'data');
});

it('never lets one person read another’s digest', function (): void {
    $w = digestWorld();
    $this->artisan('digests:build weekly_projects')->assertSuccessful();
    $digest = (string) DB::table('digests')->value('id');
    $other = $w->scenario->addMember(SystemRole::ProjectManager);

    test()->actingAs($other)->postJson("/api/digests/{$digest}/read")->assertNoContent();

    expect(DB::table('digests')->where('id', $digest)->value('read_at'))->toBeNull();
});

it('refuses an unknown digest kind', function (): void {
    $this->artisan('digests:build gossip')->assertFailed();
});

it('rings the bell once per digest, not once per run', function (): void {
    $w = digestWorld();

    $this->artisan('digests:build weekly_projects')->assertSuccessful();
    $this->artisan('digests:build weekly_projects')->assertSuccessful();

    $bell = DB::table('notifications')->where('kind', 'digest_ready')->where('user_id', $w->scenario->owner->getKey());

    expect($bell->count())->toBe(1)
        ->and((string) $bell->value('subject_id'))->toBe((string) DB::table('digests')->where('user_id', $w->scenario->owner->getKey())->value('id'));
});

it('does not announce a digest to someone who has none', function (): void {
    $w = digestWorld();
    $buyer = $w->scenario->addMember(SystemRole::MediaBuyer);

    $this->artisan('digests:build weekly_projects')->assertSuccessful();

    expect(DB::table('notifications')->where('kind', 'digest_ready')->where('user_id', $buyer->getKey())->count())->toBe(0);
});
