<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\Ad;
use Tests\Support\SyncedWorkspace;

it('opens a campaign into its ad groups and ads, with figures, signals and same-origin previews only', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $campaign = (string) Ad::query()->firstOrFail()->getAttribute('campaign_id');

    $data = test()->actingAs($w->scenario->owner)->getJson("/api/campaigns/{$campaign}/hierarchy")->assertOk()->json('data');

    expect($data['groups'])->not->toBeEmpty()
        ->and($data['writes'])->toBe(['ad_group' => 'not_documented', 'ad' => 'not_documented']);

    /** @var list<array{ads: list<array{creative: array{thumbnail_url: string|null}|null}>}> $groups */
    $groups = $data['groups'];
    $ad = $groups[0]['ads'][0];
    expect($ad)->toHaveKeys(['name', 'status', 'creative', 'ctr_now', 'ctr_change', 'frequency']);

    $thumbnail = $ad['creative']['thumbnail_url'] ?? null;
    if ($thumbnail !== null) {
        expect($thumbnail)->toStartWith('/');
    }
});

it('answers not-found for another workspace’s campaign', function (): void {
    $a = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $b = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $foreign = (string) Ad::query()->where('workspace_id', $b->scenario->workspace->getKey())->firstOrFail()->getAttribute('campaign_id');

    test()->actingAs($a->scenario->owner)->getJson("/api/campaigns/{$foreign}/hierarchy")->assertNotFound();
});
