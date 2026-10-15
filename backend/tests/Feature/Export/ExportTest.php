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
function exportableReport(): array
{
    $s = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $s->workspace->getKey()]);
    Project::factory()->forClient($client)->create();

    $report = test()->actingAs($s->owner)->postJson('/api/reports', [
        'name' => 'Monthly performance',
        'client_id' => (string) $client->getKey(),
        'window' => 'last_30_days',
        'sections' => [['kind' => 'scorecard', 'metrics' => ['spend', 'clicks']]],
    ])->assertCreated()->json('data.id');

    return ['s' => $s, 'report' => (string) $report];
}

it('queues the file on the reports queue and answers at once', function (): void {
    Queue::fake();
    ['s' => $s, 'report' => $report] = exportableReport();

    $this->actingAs($s->owner)->postJson('/api/exports', ['kind' => 'report_csv', 'subject_id' => $report])
        ->assertAccepted()
        ->assertJsonPath('data.status', 'queued');

    Queue::assertPushedOn('reports', RunExportJob::class);
});

it('produces the file, tells the requester, and lets only them fetch it', function (): void {
    Storage::fake('local');
    ['s' => $s, 'report' => $report] = exportableReport();

    $id = $this->actingAs($s->owner)->postJson('/api/exports', ['kind' => 'report_csv', 'subject_id' => $report])
        ->assertAccepted()->json('data.id');

    $list = $this->actingAs($s->owner)->getJson('/api/exports')->assertOk()->json('data');
    expect($list[0]['status'])->toBe('ready')->and($list[0]['filename'])->toEndWith('.csv');

    $this->actingAs($s->owner)->get("/api/exports/{$id}/download")
        ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertSee('Monthly performance', false);

    expect(DB::table('notifications')->where('kind', 'export_ready')->where('user_id', $s->owner->getKey())->count())->toBe(1);

    $colleague = $s->addMember(SystemRole::ProjectManager);
    $this->actingAs($colleague)->get("/api/exports/{$id}/download")->assertNotFound();
    $this->actingAs($colleague)->getJson('/api/exports')->assertOk()->assertJsonCount(0, 'data');
});

it('produces a workbook too', function (): void {
    Storage::fake('local');
    ['s' => $s, 'report' => $report] = exportableReport();

    $id = $this->actingAs($s->owner)->postJson('/api/exports', ['kind' => 'report_xlsx', 'subject_id' => $report])->json('data.id');

    $this->actingAs($s->owner)->get("/api/exports/{$id}/download")->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('refuses a report the requester cannot see, before anything is queued', function (): void {
    Queue::fake();
    ['s' => $s] = exportableReport();

    $this->actingAs($s->owner)->postJson('/api/exports', ['kind' => 'report_csv', 'subject_id' => (string) Illuminate\Support\Str::uuid()])
        ->assertNotFound();
    $this->actingAs($s->owner)->postJson('/api/exports', ['kind' => 'nonsense', 'subject_id' => 'x'])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_export');

    Queue::assertNothingPushed();
});

it('removes the file when retention lapses and keeps the record', function (): void {
    Storage::fake('local');
    ['s' => $s, 'report' => $report] = exportableReport();
    $id = $this->actingAs($s->owner)->postJson('/api/exports', ['kind' => 'report_csv', 'subject_id' => $report])->json('data.id');
    $path = (string) DB::table('export_jobs')->where('id', $id)->value('storage_path');
    Storage::disk('local')->assertExists($path);

    DB::table('export_jobs')->where('id', $id)->update(['expires_at' => now()->subMinute()]);
    $this->artisan('exports:prune')->assertSuccessful();

    Storage::disk('local')->assertMissing($path);
    expect(DB::table('export_jobs')->where('id', $id)->exists())->toBeTrue();
    $this->actingAs($s->owner)->get("/api/exports/{$id}/download")->assertNotFound();
});

it('records a failure the requester can read instead of leaving the job running', function (): void {
    Storage::fake('local');
    ['s' => $s, 'report' => $report] = exportableReport();
    Queue::fake();

    $id = $this->actingAs($s->owner)->postJson('/api/exports', ['kind' => 'report_csv', 'subject_id' => $report])->json('data.id');
    // The report is deleted between asking and the worker running.
    DB::table('reports')->where('id', $report)->delete();

    Queue::assertPushed(RunExportJob::class, function (RunExportJob $job): bool {
        app()->call([$job, 'handle']);

        return true;
    });

    expect(DB::table('export_jobs')->where('id', $id)->value('status'))->toBe('failed')
        ->and(DB::table('export_jobs')->where('id', $id)->value('error'))->not->toBeEmpty();
});
