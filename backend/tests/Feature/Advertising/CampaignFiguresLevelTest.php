<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SyncedWorkspace;

/**
 * A campaign's figures are its own campaign-level rows. Its ad groups and ads report the same money again, one level
 * down — adding them in would count every riyal two or three times.
 */
it('counts a campaign’s own rows only, however many ad-group and ad rows sit under it', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $campaign = (string) DB::table('daily_metrics')->where('entity_type', 'campaign')->where('spend_minor', '>', 0)->value('campaign_id');
    DB::table('campaigns')->where('id', $campaign)->update(['status' => 'active']);

    $read = static function () use ($w, $campaign): ?int {
        /** @var list<array{id: string, spend_minor: int|null}> $rows */
        $rows = test()->actingAs($w->scenario->owner)->getJson('/api/campaigns/control?limit=500&days=28')->assertOk()->json('data');

        return array_column($rows, 'spend_minor', 'id')[$campaign] ?? null;
    };

    $before = $read();
    expect($before)->not->toBeNull();

    // The same day's money reported again at ad-group and ad level, as a real sync does.
    foreach (DB::table('daily_metrics')->where('entity_type', 'campaign')->where('campaign_id', $campaign)->get() as $row) {
        foreach (['ad_group', 'ad'] as $level) {
            DB::table('daily_metrics')->insert([...(array) $row, 'id' => (string) Str::uuid(), 'entity_type' => $level, 'entity_id' => (string) Str::uuid()]);
        }
    }

    expect($read())->toBe($before);
});
