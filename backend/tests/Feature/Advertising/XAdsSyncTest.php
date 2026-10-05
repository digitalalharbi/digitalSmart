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
| X Ads sync
|--------------------------------------------------------------------------
|
| The same pipeline again, over the first API here whose performance data is shaped
| differently rather than merely named differently: X returns a time series per entity,
| not a row per day. If the normalisation is real, those series land in the same fact
| table, one row per entity per day, alongside Meta's and Google's.
|
*/

function xWorkspace(): SyncedWorkspace
{
    return SyncedWorkspace::with([Provider::X->value => '18ce54d4x5t']);
}

it('syncs the X hierarchy and links it together', function (): void {
    $workspace = xWorkspace();

    expect(Campaign::query()->count())->toBe(3)
        ->and(AdGroup::query()->count())->toBe(4)
        ->and(Ad::query()->count())->toBe(4)
        ->and(Creative::query()->count())->toBe(2);

    $ad = Ad::query()->where('external_id', 'eqaad')->firstOrFail();

    /*
     * The campaign was resolved through the line item, because a promoted tweet
     * carries no campaign id. Skipping the ad instead would lose the whole ad level
     * for this provider — the same hole Snapchat's ad object opened.
     */
    expect($ad->campaign_id)->not->toBeNull()
        ->and($ad->campaign?->external_id)->toBe('92ph')
        ->and($ad->adGroup?->external_id)->toBe('8u94t')
        ->and($workspace->workspaceId())->not->toBe('');
});

it('lands X micros in the same minor-unit column as everyone else', function (): void {
    xWorkspace();

    $campaign = Campaign::query()->where('external_id', '92ph')->firstOrFail();

    // 900,000,000 micros is 900.00 SAR — the column Meta's minor-unit strings and
    // Google's micros both land in, so a cross-platform total means something.
    expect($campaign->budget_minor)->toBe(90_000)
        ->and($campaign->currency)->toBe('SAR');
});

it('stores no objective on a campaign, because X has none to give', function (): void {
    xWorkspace();

    $campaign = Campaign::query()->where('external_id', '92ph')->firstOrFail();

    /*
     * On X the objective belongs to the line item, so one campaign can be pursuing
     * two at once. The line items carry theirs; the campaign claims nothing.
     */
    expect($campaign->raw_objective)->toBeNull();

    $adGroup = AdGroup::query()->where('external_id', '8u94v')->firstOrFail();

    expect($adGroup->metadata['objective'] ?? null)->toBe('VIDEO_VIEWS');
});

it('records an active campaign that cannot serve, and a paused one as paused', function (): void {
    xWorkspace();

    $incomplete = Campaign::query()->where('external_id', '92pi')->firstOrFail();
    $paused = Campaign::query()->where('external_id', '92pj')->firstOrFail();

    expect($incomplete->status)->toBe(EntityStatus::NotDelivering)
        ->and($incomplete->raw_status)->toBe('ACTIVE')
        ->and($incomplete->raw_effective_status)->toBe('INCOMPLETE')
        // Paused by the advertiser is both "paused" and "not servable". The first is
        // the one an operator can act on.
        ->and($paused->status)->toBe(EntityStatus::Paused);
});

it('expands a time series into one fact row per entity per day', function (): void {
    xWorkspace();

    $campaign = Campaign::query()->where('external_id', '92ph')->firstOrFail();

    $days = DailyMetric::query()
        ->where('campaign_id', $campaign->getKey())
        ->whereNull('ad_group_id')
        ->whereNull('ad_id')
        ->pluck('date')
        ->map(static fn (mixed $date): string => (string) $date)
        ->all();

    /*
     * Fourteen days, each one once. X sent one row holding arrays; a sync that read
     * only the first bucket would have written one day and lost thirteen.
     */
    expect($days)->toHaveCount(14)
        ->and(array_unique($days))->toHaveCount(14);
});

it('covers the whole attribution window despite a seven-day request ceiling', function (): void {
    xWorkspace();

    $oldest = DailyMetric::query()->min('date');
    $newest = DailyMetric::query()->max('date');

    /*
     * X answers at most seven days per synchronous stats request, and its conversions
     * keep landing for four weeks. Clamping the range to one request would have left
     * everything older than a week unread for ever — so the sync asks in consecutive
     * windows, and a fortnight of fixture days all arrive.
     */
    expect($oldest)->not->toBeNull()
        ->and($newest)->not->toBeNull();

    $span = (new DateTimeImmutable((string) $newest))->diff(new DateTimeImmutable((string) $oldest))->days;

    expect($span)->toBeGreaterThan(7);
});

it('keeps reach null everywhere, because X reports it from a different endpoint', function (): void {
    xWorkspace();

    /*
     * `stats/.../reach/campaigns` is its own call with its own range limits and is not
     * part of a stats response. Impressions passed off as people is the easy lie here,
     * and the column stays empty instead.
     */
    expect(DailyMetric::query()->whereNotNull('reach')->count())->toBe(0)
        ->and(DailyMetric::query()->whereNotNull('impressions')->count())->toBeGreaterThan(0);
});

it('keeps revenue null, because X publishes conversion counts and not their worth', function (): void {
    xWorkspace();

    expect(DailyMetric::query()->whereNotNull('revenue_minor')->count())->toBe(0)
        ->and(ConversionMetric::query()->whereNotNull('value_minor')->count())->toBe(0)
        // The counts themselves are real and stored.
        ->and(ConversionMetric::query()->where('raw_action_type', 'conversion_purchases')->count())
        ->toBeGreaterThan(0);
});

it('stores link clicks as its own figure, below total clicks', function (): void {
    xWorkspace();

    $both = DailyMetric::query()
        ->whereNotNull('link_clicks')
        ->whereColumn('link_clicks', '<', 'clicks')
        ->count();

    // `url_clicks` is X's own metric rather than a share of `clicks`, so the two CTRs
    // differ for a reason that is the platform's.
    expect($both)->toBeGreaterThan(0);
});

it('leaves a metric X never sent null rather than zero', function (): void {
    xWorkspace();

    $videoAdGroup = AdGroup::query()->where('external_id', '8u94v')->firstOrFail();

    $rows = DailyMetric::query()
        ->where('ad_group_id', $videoAdGroup->getKey())
        ->whereNull('ad_id')
        ->get();

    /*
     * A video-view line item has no link to click, so X omits the metric entirely.
     * Writing a zero would make "nobody clicked a link" indistinguishable from "there
     * was no link", and the second is the truth here.
     */
    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row->link_clicks)->toBeNull()
            ->and($row->video_views)->toBeGreaterThan(0);
    }
});

it('writes nothing at all for a campaign that reported nothing', function (): void {
    xWorkspace();

    $paused = Campaign::query()->where('external_id', '92pj')->firstOrFail();

    // A paused campaign reports no rows, which is not the same as rows of zeros. The
    // absence has to survive, or a pacing chart invents a flat line at nought.
    expect(DailyMetric::query()->where('campaign_id', $paused->getKey())->count())->toBe(0);
});

it('rolls up to the same totals at every level', function (): void {
    xWorkspace();

    $campaign = Campaign::query()->where('external_id', '92ph')->firstOrFail();

    $atCampaign = (int) DailyMetric::query()
        ->where('campaign_id', $campaign->getKey())
        ->whereNull('ad_group_id')
        ->whereNull('ad_id')
        ->sum('impressions');

    $atAdGroup = (int) DailyMetric::query()
        ->where('campaign_id', $campaign->getKey())
        ->whereNotNull('ad_group_id')
        ->whereNull('ad_id')
        ->sum('impressions');

    /*
     * X computes each level itself and reports no deduplicated reach, so impressions
     * *are* additive here — unlike Meta, where a parent's reach is deliberately less
     * than the sum of its children's. Equal totals is therefore the correct answer for
     * this provider, and a drift would mean the series were being read twice or
     * misaligned.
     */
    expect($atCampaign)->toBe($atAdGroup)
        ->and($atCampaign)->toBeGreaterThan(0);
});

it('stamps every X sandbox row as sandbox', function (): void {
    xWorkspace();

    expect(DailyMetric::query()->distinct()->pluck('source')->all())
        ->toBe([MetricSource::Sandbox]);
});

it('refuses outright when the X credentials are real', function (): void {
    $workspace = xWorkspace();
    $repository = app(ProviderConnectionRepository::class);
    $workspaceId = new WorkspaceId($workspace->workspaceId());

    $connection = $repository->findForProvider($workspaceId, Provider::X);
    expect($connection)->not->toBeNull();

    /** @var ProviderConnection $connection */
    $connection->replaceCredentials(new Credentials([
        'consumer_key' => 'a-real-looking-consumer-key',
        'consumer_secret' => 'a-real-looking-consumer-secret',
    ]));
    $repository->save($connection);

    DailyMetric::query()->delete();
    Campaign::query()->delete();

    $outcome = app(SyncBoundAccount::class)(
        $workspace->workspaceId(),
        (string) $workspace->account(Provider::X)->getKey(),
    );

    /*
     * BLOCKED_EXTERNAL. No X app has been granted Ads API access, so there is nothing
     * to call — and the one thing that must never happen is a plausible number
     * appearing anyway.
     */
    expect($outcome->status)->toBe(SyncStatus::Failed)
        ->and($outcome->failureMessage)->toContain('has not been granted API access')
        ->and(Campaign::query()->count())->toBe(0)
        ->and(DailyMetric::query()->count())->toBe(0);
});

it('is idempotent for X as well', function (): void {
    $workspace = xWorkspace();
    $before = [
        'campaigns' => Campaign::query()->count(),
        'metrics' => DailyMetric::query()->count(),
        'conversions' => ConversionMetric::query()->count(),
    ];

    app(SyncBoundAccount::class)(
        $workspace->workspaceId(),
        (string) $workspace->account(Provider::X)->getKey(),
    );

    expect(Campaign::query()->count())->toBe($before['campaigns'])
        ->and(DailyMetric::query()->count())->toBe($before['metrics'])
        ->and(ConversionMetric::query()->count())->toBe($before['conversions'])
        ->and($before['metrics'])->toBeGreaterThan(0);
});
