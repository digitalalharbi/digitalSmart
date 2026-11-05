<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SyncedWorkspace;

/*
 * The monitor, in its two registers.
 *
 * What is asserted is the line between them: an operator holding integration rights sees queue depth and
 * the failing runs; everyone else sees plain counts and nothing technical.
 */
it('gives a manager plain counts and withholds the technical register', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $manager = $w->scenario->addMember(SystemRole::ProjectManager);

    $data = test()->actingAs($manager)->getJson('/api/integrations/operations')->assertOk()->json('data');

    expect($data['summary'])->toHaveKeys(['connections', 'stale_accounts', 'last_sync_at', 'next_sync_at'])
        ->and($data['summary']['connections'])->toHaveKeys(['healthy', 'needs_reconnect', 'sync_issue', 'awaiting_access'])
        ->and($data['advanced'])->toBeNull();
});

it('shows an administrator queue depth for every queue and the per-provider error rate', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    $data = test()->actingAs($w->scenario->owner)->getJson('/api/integrations/operations')->assertOk()->json('data');

    expect($data['advanced']['queue_depth'])->toHaveKeys(['sync-recovery', 'sync', 'sync-low', 'sync-backfill'])
        ->and($data['advanced']['providers'])->not->toBeEmpty()
        ->and($data['advanced']['providers'][0])->toHaveKeys(['provider', 'runs', 'failed', 'error_rate']);
});

it('counts an account that was read just now as syncing and not stale', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    $summary = test()->actingAs($w->scenario->owner)->getJson('/api/integrations/operations')->json('data.summary');

    expect($summary['accounts_syncing'])->toBe(1)
        ->and($summary['stale_accounts'])->toBe(0)
        ->and($summary['last_sync_at'])->not->toBeNull();
});

it('does not show one workspace another’s sync trouble', function (): void {
    $a = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $b = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1002']);

    $seenByB = test()->actingAs($b->scenario->owner)->getJson('/api/integrations/operations')->json('data.summary');

    expect($seenByB['accounts_syncing'])->toBe(1);
    expect($a->workspaceId())->not->toBe($b->workspaceId());
});

it('rolls tracking up by project with a fixed vocabulary', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    $data = test()->actingAs($w->scenario->owner)->getJson('/api/measurement/agency-health')->assertOk()->json('data');

    expect($data['totals'])->toHaveKeys(['healthy', 'warning', 'critical'])
        ->and($data)->toHaveKeys(['projects', 'by_kind', 'workspace_issues']);

    $creative = $w->scenario->addMember(SystemRole::Creative);
    test()->actingAs($creative)->getJson('/api/measurement/agency-health')->assertForbidden();
});

/** @return list<array<string, mixed>> */
function operationsConnectionHistory(object $as): array
{
    /** @var list<array<string, mixed>> $history */
    $history = test()->actingAs($as)->getJson('/api/integrations/operations')->assertOk()->json('data.advanced.connection_history');

    return $history;
}

function operationsFailedRun(SyncedWorkspace $w, string $at, string $code = 'rate_limited'): void
{
    $account = $w->account(Provider::Meta);

    DB::table('sync_runs')->insert([
        'id' => (string) Str::uuid(), 'workspace_id' => $w->workspaceId(),
        'provider_connection_id' => $account->provider_connection_id, 'external_account_id' => $account->getKey(),
        'provider' => Provider::Meta->value, 'kind' => 'insights', 'status' => 'failed',
        'error_code' => $code, 'error_message' => 'The platform refused the read.', 'metadata' => '{}',
        'started_at' => $at, 'finished_at' => $at, 'created_at' => $at, 'updated_at' => $at,
    ]);
}

it('keeps a connection’s last attempt apart from its last success', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $later = now()->addHours(2)->format('Y-m-d H:i:s');
    operationsFailedRun($w, now()->addHour()->format('Y-m-d H:i:s'), 'token_expired');
    operationsFailedRun($w, $later);

    $history = operationsConnectionHistory($w->scenario->owner);

    expect($history)->toHaveCount(1)
        ->and($history[0]['provider'])->toBe('meta')
        // The attempt is the recent failure; the success is the earlier read, and both are stated.
        ->and($history[0]['last_attempt_status'])->toBe('failed')
        ->and($history[0]['last_success_at'])->not->toBeNull()
        ->and($history[0]['last_attempt_at'] > $history[0]['last_success_at'])->toBeTrue()
        ->and($history[0]['last_error_code'])->toBe('rate_limited')
        ->and($history[0]['failures_since_success'])->toBe(2);
});

it('reports no failure for a connection that has only succeeded', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    $history = operationsConnectionHistory($w->scenario->owner);

    expect($history[0]['last_attempt_status'])->toBe('succeeded')
        ->and($history[0]['last_failure_at'])->toBeNull()
        ->and($history[0]['last_error_code'])->toBeNull()
        ->and($history[0]['failures_since_success'])->toBe(0);
});

it('keeps one workspace’s connection history out of another’s', function (): void {
    $a = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $b = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1002']);
    operationsFailedRun($a, now()->addHour()->format('Y-m-d H:i:s'));

    $seenByB = operationsConnectionHistory($b->scenario->owner);

    expect($seenByB)->toHaveCount(1)
        ->and($seenByB[0]['failures_since_success'])->toBe(0)
        ->and($seenByB[0]['connection_id'])->toBe($b->account(Provider::Meta)->provider_connection_id);
});
