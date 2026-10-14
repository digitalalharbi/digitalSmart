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
| LinkedIn sync
|--------------------------------------------------------------------------
|
| The same pipeline over an API whose hierarchy is named to mislead: a LinkedIn
| "campaign" is an ad set, so a campaign group lands at our campaign level and a
| campaign at our ad group level. If that mapping is wrong, every roll-up in the
| product is off by one level — which is what these tests are really checking.
|
*/

function linkedInWorkspace(): SyncedWorkspace
{
    return SyncedWorkspace::with([Provider::LinkedIn->value => '512352200']);
}

it('lands the LinkedIn hierarchy one level down from its own names', function (): void {
    $workspace = linkedInWorkspace();

    // Three campaign groups, four campaigns, four creatives.
    expect(Campaign::query()->count())->toBe(3)
        ->and(AdGroup::query()->count())->toBe(4)
        ->and(Ad::query()->count())->toBe(4)
        ->and(Creative::query()->count())->toBe(4);

    $ad = Ad::query()->where('external_id', '119962155')->firstOrFail();

    /*
     * The chain that proves the mapping: creative → campaign → campaign group. The
     * creative names only its campaign, so the campaign group was resolved from the
     * campaign the sync had already written.
     */
    expect($ad->adGroup?->external_id)->toBe('360035215')
        ->and($ad->campaign?->external_id)->toBe('603407684')
        ->and($ad->creative?->external_id)->toBe('119962155')
        ->and($workspace->workspaceId())->not->toBe('');
});

it('reads a decimal budget string into the same minor-unit column as micros', function (): void {
    linkedInWorkspace();

    $campaign = Campaign::query()->where('external_id', '603407684')->firstOrFail();

    // "180000.00" SAR. Meta's minor-unit strings, Google's and Snapchat's micros, X's
    // micros and this all land in one column, which is what makes a cross-platform
    // total mean anything.
    expect($campaign->budget_minor)->toBe(18_000_000)
        ->and($campaign->currency)->toBe('SAR');
});

it('records an active group on budget hold as not delivering', function (): void {
    linkedInWorkspace();

    $held = Campaign::query()->where('external_id', '603407690')->firstOrFail();

    expect($held->status)->toBe(EntityStatus::NotDelivering)
        ->and($held->raw_status)->toBe('ACTIVE')
        ->and($held->raw_effective_status)->toBe('CAMPAIGN_GROUP_TOTAL_BUDGET_HOLD');
});

it('stores a rejected creative with the reason it was refused', function (): void {
    linkedInWorkspace();

    $rejected = Ad::query()->where('external_id', '119962171')->firstOrFail();

    expect($rejected->status)->toBe(EntityStatus::Rejected)
        ->and($rejected->review_feedback)->toBe('CONTENT_POLICY_VIOLATION');
});

it('writes a fact row per entity per day at every level', function (): void {
    linkedInWorkspace();

    $campaign = Campaign::query()->where('external_id', '603407684')->firstOrFail();

    $days = DailyMetric::query()
        ->where('campaign_id', $campaign->getKey())
        ->whereNull('ad_group_id')
        ->whereNull('ad_id')
        ->pluck('date')
        ->map(static fn (mixed $date): string => (string) $date)
        ->all();

    expect($days)->toHaveCount(14)
        ->and(array_unique($days))->toHaveCount(14);
});

it('stores reach, and keeps a parent below the sum of its children', function (): void {
    linkedInWorkspace();

    $campaign = Campaign::query()->where('external_id', '603407684')->firstOrFail();

    $atCampaign = (int) DailyMetric::query()
        ->where('campaign_id', $campaign->getKey())
        ->whereNull('ad_group_id')
        ->whereNull('ad_id')
        ->sum('reach');

    $atAdGroup = (int) DailyMetric::query()
        ->where('campaign_id', $campaign->getKey())
        ->whereNotNull('ad_group_id')
        ->whereNull('ad_id')
        ->sum('reach');

    /*
     * `approximateMemberReach` estimates unique *members*, so one person reached by two
     * ad sets is one member at the group level and two if you add the levels up. A test
     * that passed by summing the wrong level would hide exactly that mistake.
     */
    expect($atCampaign)->toBeLessThan($atAdGroup)
        ->and($atCampaign)->toBeGreaterThan(0);
});

it('stores conversion value as revenue', function (): void {
    linkedInWorkspace();

    // LinkedIn publishes what the conversions were worth, unlike X. One total per row,
    // so it is revenue rather than a value on a conversion type.
    expect(DailyMetric::query()->whereNotNull('revenue_minor')->count())->toBeGreaterThan(0)
        ->and(ConversionMetric::query()->whereNotNull('value_minor')->count())->toBe(0)
        ->and(ConversionMetric::query()->where('raw_action_type', 'oneClickLeads')->count())
        ->toBeGreaterThan(0);
});

it('leaves a metric LinkedIn never sent null rather than zero', function (): void {
    linkedInWorkspace();

    $video = AdGroup::query()->where('external_id', '360035230')->firstOrFail();

    $rows = DailyMetric::query()
        ->where('ad_group_id', $video->getKey())
        ->whereNull('ad_id')
        ->get();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        /*
         * A video-views campaign has no landing page to click through to, so LinkedIn
         * omits the metric. A zero would say nobody clicked, which is a different and
         * false claim.
         */
        expect($row->link_clicks)->toBeNull()
            ->and($row->video_views)->toBeGreaterThan(0);
    }
});

it('writes nothing for the campaign group that reported nothing', function (): void {
    linkedInWorkspace();

    $default = Campaign::query()->where('external_id', '504293601')->firstOrFail();

    // The paused default group has no rows at all, which is not the same as rows of
    // zeros — a pacing chart would otherwise draw a flat line at nought.
    expect(DailyMetric::query()->where('campaign_id', $default->getKey())->count())->toBe(0);
});

it('stamps every LinkedIn sandbox row as sandbox', function (): void {
    linkedInWorkspace();

    expect(DailyMetric::query()->distinct()->pluck('source')->all())
        ->toBe([MetricSource::Sandbox]);
});

it('refuses outright when the LinkedIn credentials are real', function (): void {
    $workspace = linkedInWorkspace();
    $repository = app(ProviderConnectionRepository::class);

    $connection = $repository->agencyConnection(new WorkspaceId($workspace->workspaceId()), Provider::LinkedIn);
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
        (string) $workspace->account(Provider::LinkedIn)->getKey(),
    );

    /*
     * BLOCKED_EXTERNAL. No LinkedIn app has been admitted to the Advertising API
     * programme, so there is nothing to call — and the one thing that must never happen
     * is a plausible number appearing anyway.
     */
    expect($outcome->status)->toBe(SyncStatus::Failed)
        ->and($outcome->failureMessage)->toContain('has not been granted API access')
        ->and(Campaign::query()->count())->toBe(0)
        ->and(DailyMetric::query()->count())->toBe(0);
});

it('is idempotent for LinkedIn as well', function (): void {
    $workspace = linkedInWorkspace();
    $before = [
        'campaigns' => Campaign::query()->count(),
        'metrics' => DailyMetric::query()->count(),
        'conversions' => ConversionMetric::query()->count(),
    ];

    app(SyncBoundAccount::class)(
        $workspace->workspaceId(),
        (string) $workspace->account(Provider::LinkedIn)->getKey(),
    );

    expect(Campaign::query()->count())->toBe($before['campaigns'])
        ->and(DailyMetric::query()->count())->toBe($before['metrics'])
        ->and(ConversionMetric::query()->count())->toBe($before['conversions'])
        ->and($before['metrics'])->toBeGreaterThan(0);
});
