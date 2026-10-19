<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use Tests\Support\SyncedWorkspace;

/**
 * Findings travel as codes with values, so an interface writes them in the reader's language and never shows the
 * server's English sentence to an Arabic reader. The sentence stays beside the code as the fallback.
 */
it('gives every campaign verdict a code, and every attention item from campaigns and budgets one too', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001', Provider::GoogleAds->value => 'act_sandbox_2001']);
    $owner = $w->scenario->owner;

    $rows = test()->actingAs($owner)->getJson('/api/campaigns/control?limit=50')->assertOk()->json('data');

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row['health_reason_code'])->toStartWith('CAMPAIGN_')
            ->and($row['health_reason'])->not->toBe('');
    }

    $items = test()->actingAs($owner)->getJson('/api/attention?limit=100')->assertOk()->json('data');

    foreach ($items as $item) {
        if (in_array($item['stream'], ['campaigns', 'budgets', 'tasks', 'approvals', 'requests', 'tracking', 'automation'], true)) {
            expect($item['reason'])->not->toBeNull()
                ->and($item['reason']['code'])->toMatch('/^[A-Z_]+$/');
        }
    }
});
