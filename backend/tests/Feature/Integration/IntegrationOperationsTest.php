<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
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
