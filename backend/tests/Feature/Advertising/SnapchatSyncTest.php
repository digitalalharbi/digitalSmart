<?php

declare(strict_types=1);

use App\Application\Advertising\Command\SyncBoundAccount;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Advertising\MetricSource;
use App\Domain\Advertising\SyncStatus;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\Ad;
use App\Infrastructure\Persistence\Eloquent\Model\AdGroup;
use App\Infrastructure\Persistence\Eloquent\Model\Campaign;
use App\Infrastructure\Persistence\Eloquent\Model\ConversionMetric;
use App\Infrastructure\Persistence\Eloquent\Model\Creative;
use App\Infrastructure\Persistence\Eloquent\Model\DailyMetric;
use Tests\Support\SyncedWorkspace;

/*
|--------------------------------------------------------------------------
| Snapchat sync
|--------------------------------------------------------------------------
|
| The same pipeline as the other providers, over an API whose list responses are
| wrapped twice and whose ad object has no campaign id at all. If the normalisation is
| real, Snapchat lands in the same tables with the same meaning as Meta and Google.
|
*/

function snapchatWorkspace(): SyncedWorkspace
{
    return SyncedWorkspace::with([
        Provider::Snapchat->value => 'd8a2b1c4-6e5f-4a3b-9c8d-1e2f3a4b5c6d',
    ]);
}

it('syncs the Snapchat hierarchy and links it together', function (): void {
    $workspace = snapchatWorkspace();

    expect(Campaign::query()->count())->toBe(4)
        ->and(AdGroup::query()->count())->toBe(5)
        ->and(Ad::query()->count())->toBe(5)
        ->and(Creative::query()->count())->toBe(5);

    $ad = Ad::query()->where('external_id', '33333333-aaaa-4bbb-8ccc-000000000001')->firstOrFail();

    /*
     * The campaign was resolved through the ad squad, because Snapchat's ad carries no
     * campaign id. Skipping the ad instead would have lost the entire ad level for this
     * provider.
     */
    expect($ad->campaign_id)->not->toBeNull()
        ->and($ad->campaign?->external_id)->toBe('11111111-aaaa-4bbb-8ccc-000000000001')
        ->and($ad->adGroup?->external_id)->toBe('22222222-aaaa-4bbb-8ccc-000000000001')
        ->and($ad->creative?->external_id)->toBe('44444444-aaaa-4bbb-8ccc-000000000001')
        ->and($workspace->workspaceId())->not->toBe('');
});

it('lands Snapchat micros in the same minor-unit column as everyone else', function (): void {
    snapchatWorkspace();

    $campaign = Campaign::query()->where('external_id', '11111111-aaaa-4bbb-8ccc-000000000001')->firstOrFail();

    // Micros on the wire; the column Meta's minor-unit strings and Google's micros
    // both land in, so a cross-platform total means something.
    expect($campaign->budget_minor)->toBe(90_000)
        ->and($campaign->currency)->toBe('SAR');
});

it('records a campaign that is active and not delivering', function (): void {
    snapchatWorkspace();

    $capped = Campaign::query()->where('external_id', '11111111-aaaa-4bbb-8ccc-000000000002')->firstOrFail();

    expect($capped->status)->toBe(EntityStatus::NotDelivering)
        ->and($capped->raw_status)->toBe('ACTIVE')
        ->and($capped->raw_effective_status)->toBe('PAUSED_BY_SPEND_CAP');
});

it('drops a list item whose own request failed rather than writing an empty row', function (): void {
    $workspace = SyncedWorkspace::with([
        Provider::Snapchat->value => 'd8a2b1c4-6e5f-4a3b-9c8d-1e2f3a4b5c6d',
    ]);

    /*
     * Snapchat batches can partly succeed, and a failed item carries no entity. Every
     * campaign that *was* written has a real external id — an empty one would mean a
     * failed item had been mapped anyway.
     */
    expect(Campaign::query()->where('external_id', '')->count())->toBe(0)
        ->and(Campaign::query()->count())->toBeGreaterThan(0)
        ->and($workspace->workspaceId())->not->toBe('');
});

it('stores reach, and keeps it below impressions', function (): void {
    snapchatWorkspace();

    $withReach = DailyMetric::query()
        ->whereNotNull('reach')
        ->whereColumn('reach', '<', 'impressions')
        ->count();

    // Snapchat's `uniques` is deduplicated reach, so it can never exceed impressions —
    // which a CHECK constraint also enforces.
    expect($withReach)->toBeGreaterThan(0);
});

it('keeps link clicks null, because a swipe is the only click Snapchat reports', function (): void {
    snapchatWorkspace();

    expect(DailyMetric::query()->whereNotNull('link_clicks')->count())->toBe(0)
        ->and(DailyMetric::query()->whereNotNull('clicks')->count())->toBeGreaterThan(0);
});

it('keeps engagements null, because Snapchat publishes no such figure', function (): void {
    snapchatWorkspace();

    // Screen time and attachment views are real, but adding them together would
    // produce a number nobody defined.
    expect(DailyMetric::query()->whereNotNull('engagements')->count())->toBe(0);
});

it('writes a conversion row per reported column', function (): void {
    snapchatWorkspace();

    $purchase = ConversionMetric::query()->where('raw_action_type', 'conversion_purchases')->firstOrFail();

    expect($purchase->count)->toBeGreaterThan(0)
        ->and($purchase->value_minor)->toBeGreaterThan(0)
        ->and($purchase->currency)->toBe('SAR')
        // Only purchases carry a value; the rest must not borrow one.
        ->and(
            ConversionMetric::query()
                ->where('raw_action_type', 'conversion_add_cart')
                ->whereNotNull('value_minor')
                ->count()
        )->toBe(0);
});

it('stamps every Snapchat sandbox row as sandbox', function (): void {
    snapchatWorkspace();

    expect(DailyMetric::query()->distinct()->pluck('source')->all())
        ->toBe([MetricSource::Sandbox]);
});

it('refuses outright when the Snapchat credentials are real', function (): void {
    $workspace = snapchatWorkspace();
    $repository = app(ProviderConnectionRepository::class);
    $workspaceId = new WorkspaceId($workspace->workspaceId());

    $connection = $repository->agencyConnection($workspaceId, Provider::Snapchat);
    expect($connection)->not->toBeNull();

    /** @var ProviderConnection $connection */
    $connection->replaceCredentials(new Credentials([
        'client_id' => 'a-real-looking-client-id',
        'client_secret' => 'a-real-looking-secret',
    ]));
    $repository->save($connection);

    DailyMetric::query()->delete();
    Campaign::query()->delete();

    $outcome = app(SyncBoundAccount::class)(
        $workspace->workspaceId(),
        (string) $workspace->account(Provider::Snapchat)->getKey(),
    );

    /*
     * BLOCKED_EXTERNAL. No Snap app has been approved, so there is nothing to call —
     * and the one thing that must never happen is a plausible number appearing anyway.
     */
    expect($outcome->status)->toBe(SyncStatus::Failed)
        ->and($outcome->failureMessage)->toContain('has not been granted API access')
        ->and(Campaign::query()->count())->toBe(0)
        ->and(DailyMetric::query()->count())->toBe(0);
});

it('is idempotent for Snapchat as well', function (): void {
    $workspace = snapchatWorkspace();
    $before = [
        'campaigns' => Campaign::query()->count(),
        'metrics' => DailyMetric::query()->count(),
        'conversions' => ConversionMetric::query()->count(),
    ];

    app(SyncBoundAccount::class)(
        $workspace->workspaceId(),
        (string) $workspace->account(Provider::Snapchat)->getKey(),
    );

    expect(Campaign::query()->count())->toBe($before['campaigns'])
        ->and(DailyMetric::query()->count())->toBe($before['metrics'])
        ->and(ConversionMetric::query()->count())->toBe($before['conversions'])
        ->and($before['metrics'])->toBeGreaterThan(0);
});
