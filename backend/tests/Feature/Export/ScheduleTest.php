<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Interface\Queue\RunExportJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WorkspaceScenario;

/** @return array{s: WorkspaceScenario, report: string} */
function scheduledReport(): array
{
    $s = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $s->workspace->getKey()]);
    Project::factory()->forClient($client)->create();

    $report = test()->actingAs($s->owner)->postJson('/api/reports', [
        'name' => 'Weekly', 'client_id' => (string) $client->getKey(), 'window' => 'last_7_days',
        'sections' => [['kind' => 'scorecard', 'metrics' => ['spend']]],
    ])->assertCreated()->json('data.id');

    return ['s' => $s, 'report' => (string) $report];
}

it('schedules a report for the person who asked and shows only theirs', function (): void {
    ['s' => $s, 'report' => $report] = scheduledReport();

    $id = $this->actingAs($s->owner)->postJson('/api/exports/schedules', [
        'report_id' => $report, 'kind' => 'report_csv', 'cadence' => 'weekly', 'weekday' => 1, 'hour' => 7,
    ])->assertCreated()->assertJsonPath('data.cadence', 'weekly')->assertJsonPath('data.hour_utc', 7)->json('data.id');

    $other = $s->addMember(SystemRole::ProjectManager);
    $this->actingAs($other)->getJson('/api/exports/schedules')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($other)->deleteJson("/api/exports/schedules/{$id}")->assertNotFound();
    $this->actingAs($s->owner)->getJson('/api/exports/schedules')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($s->owner)->deleteJson("/api/exports/schedules/{$id}")->assertNoContent();
});

it('queues what is due, as its owner, and sets the next run', function (): void {
    Storage::fake('local');
    Queue::fake();
    ['s' => $s, 'report' => $report] = scheduledReport();
    $id = $this->actingAs($s->owner)->postJson('/api/exports/schedules', [
        'report_id' => $report, 'kind' => 'report_csv', 'cadence' => 'daily', 'hour' => 6,
    ])->json('data.id');
    DB::table('report_schedules')->where('id', $id)->update(['next_run_at' => now()->subMinute()]);

    $this->artisan('reports:run-schedules')->assertSuccessful();

    Queue::assertPushedOn('reports', RunExportJob::class);
    expect(DB::table('export_jobs')->where('user_id', $s->owner->getKey())->count())->toBe(1)
        ->and(DB::table('report_schedules')->where('id', $id)->value('last_run_at'))->not->toBeNull()
        ->and(strtotime((string) DB::table('report_schedules')->where('id', $id)->value('next_run_at')))->toBeGreaterThan(time());
});

it('does not run a schedule before its time', function (): void {
    Queue::fake();
    ['s' => $s, 'report' => $report] = scheduledReport();
    $this->actingAs($s->owner)->postJson('/api/exports/schedules', ['report_id' => $report, 'kind' => 'report_csv', 'cadence' => 'monthly', 'day_of_month' => 1])->assertCreated();
    DB::table('report_schedules')->update(['next_run_at' => now()->addDay()]);

    $this->artisan('reports:run-schedules')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('halts a schedule whose report its owner can no longer see, with the reason', function (): void {
    Queue::fake();
    ['s' => $s, 'report' => $report] = scheduledReport();
    $id = $this->actingAs($s->owner)->postJson('/api/exports/schedules', ['report_id' => $report, 'kind' => 'report_csv', 'cadence' => 'daily'])->json('data.id');
    DB::table('report_schedules')->where('id', $id)->update(['next_run_at' => now()->subMinute()]);
    // A report that is deleted takes its schedules with it, so the case that matters is the owner losing
    // access while the report stays: a suspended member is refused by the same use case a live request is.
    DB::table('workspace_members')->where('user_id', $s->owner->getKey())->update(['status' => 'suspended']);

    $this->artisan('reports:run-schedules')->assertSuccessful();

    expect(DB::table('report_schedules')->where('id', $id)->value('next_run_at'))->toBeNull()
        ->and(DB::table('report_schedules')->where('id', $id)->value('last_error'))->not->toBeNull();
    Queue::assertNothingPushed();
});

it('refuses a schedule for a report that is not there', function (): void {
    ['s' => $s] = scheduledReport();

    $this->actingAs($s->owner)->postJson('/api/exports/schedules', [
        'report_id' => (string) Illuminate\Support\Str::uuid(), 'kind' => 'report_csv', 'cadence' => 'daily',
    ])->assertNotFound();
});
