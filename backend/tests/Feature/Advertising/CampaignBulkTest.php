<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SyncedWorkspace;

/**
 * One action across many campaigns, each going through its own use case: approval for every change, the proposal
 * limits for every budget, a separate answer per campaign, and nothing changed on a platform.
 */
function bulkWorld(): SyncedWorkspace
{
    return SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
}

it('asks for a pause per campaign — as the person, each waiting for approval — and answers per campaign', function (): void {
    $w = bulkWorld();
    $ids = DB::table('campaigns')->where('status', 'active')->limit(3)->pluck('id')->all();
    $missing = (string) Str::uuid();

    $result = test()->actingAs($w->scenario->owner)->postJson('/api/campaigns/bulk', [
        'campaign_ids' => [...$ids, $missing],
        'action' => 'request_pause',
        'reason' => 'End of the flight',
    ])->assertOk()->json('data');

    expect($result['done'])->toBe(count($ids))
        ->and(array_column($result['results'], 'code', 'campaign_id')[$missing])->toBe('not_found')
        ->and(DB::table('approval_requests')->where('kind', 'campaign_pause')->where('origin', 'person')->where('state', 'pending')->count())->toBe(count($ids))
        ->and(DB::table('campaigns')->whereIn('id', $ids)->where('status', 'active')->count())->toBe(count($ids))
        ->and(DB::table('audit_logs')->where('action', 'campaign.bulk_action')->count())->toBe(1);
});

it('keeps the budget limits on every campaign in a bulk change, and refuses a change past them', function (): void {
    $w = bulkWorld();
    $ids = DB::table('campaigns')->where('status', 'active')->where('budget_mode', 'daily')->whereNotNull('budget_minor')->limit(2)->pluck('id')->all();

    test()->actingAs($w->scenario->owner)->postJson('/api/campaigns/bulk', ['campaign_ids' => $ids, 'action' => 'request_budget_change', 'reason' => 'Too high', 'change_pct' => 60])->assertStatus(422);

    $result = test()->actingAs($w->scenario->owner)->postJson('/api/campaigns/bulk', ['campaign_ids' => $ids, 'action' => 'request_budget_change', 'reason' => 'Trim', 'change_pct' => -10])->assertOk()->json('data');
    // Asked twice: the second is refused per campaign — no stacking.
    $again = test()->actingAs($w->scenario->owner)->postJson('/api/campaigns/bulk', ['campaign_ids' => $ids, 'action' => 'request_budget_change', 'reason' => 'Trim', 'change_pct' => -10])->assertOk()->json('data');

    expect($result['done'])->toBe(count($ids))
        ->and($again['done'])->toBe(0)
        ->and(DB::table('approval_requests')->where('kind', 'spend_change')->where('origin', 'person')->whereNull('agent')->count())->toBe(count($ids));
});

it('is refused to someone who cannot read campaigns', function (): void {
    $w = bulkWorld();
    $viewer = $w->scenario->addMember(SystemRole::ClientViewer);
    $ids = DB::table('campaigns')->limit(2)->pluck('id')->all();

    test()->actingAs($viewer)->postJson('/api/campaigns/bulk', ['campaign_ids' => $ids, 'action' => 'request_pause', 'reason' => 'x x x'])->assertForbidden();
    expect(DB::table('approval_requests')->count())->toBe(0);
});
