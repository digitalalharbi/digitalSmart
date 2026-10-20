<?php

declare(strict_types=1);

use App\Application\Commerce\Contract\CommerceStore;
use App\Application\Commerce\DTO\OrderRecord;
use App\Domain\Advertising\MetricSource;
use App\Domain\Commerce\FulfilmentState;
use App\Domain\Commerce\OrderAttribution;
use App\Domain\Commerce\PaymentState;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SyncedWorkspace;

/*
|--------------------------------------------------------------------------
| No internal identifier in what a visitor receives
|--------------------------------------------------------------------------
|
| A shared report is read by somebody with a link and no account. The agency's own screens have a use for a campaign's
| id; a visitor has none, and a handle on the database is not something to hand them. Every id the fixtures create is
| collected up front and then searched for in the serialised response — so the test cannot pass by looking for the
| wrong field — and, beyond the known ones, nothing UUID-shaped may appear at all.
|
*/

const UUID_SHAPE = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

/** @return list<string> every id in the tables a report is built from, to look for in what a visitor receives */
function internalIds(): array
{
    $ids = [];

    foreach (['campaigns', 'ad_groups', 'ads', 'external_accounts', 'clients', 'projects', 'workspaces', 'reports', 'report_shares', 'report_snapshots', 'stores', 'leads', 'store_customers'] as $table) {
        foreach (DB::table($table)->pluck('id') as $id) {
            $ids[] = (string) $id;
        }
    }

    return array_values(array_unique($ids));
}

/** @param array<string, mixed>|string $payload */
function assertNoInternalIds(array|string $payload): void
{
    $body = is_string($payload) ? $payload : (string) json_encode($payload);
    $known = internalIds();

    // A fixture with nothing in it would pass vacuously.
    expect($known)->not->toBeEmpty();

    foreach ($known as $id) {
        expect($body)->not->toContain($id);
    }

    expect(preg_match(UUID_SHAPE, $body))->toBe(0);
}

/** @return array{workspace: SyncedWorkspace, report: string, client: Client, store: Store} */
function idsScenario(): array
{
    $workspace = SyncedWorkspace::default();
    $client = Client::factory()->create(['workspace_id' => $workspace->scenario->workspace->getKey()]);

    /** @var Store $store */
    $store = Store::query()->create([
        'workspace_id' => $workspace->scenario->workspace->getKey(),
        'client_id' => $client->getKey(),
        'provider' => Provider::Salla->value,
        'external_id' => 'ids-store-1',
        'name' => 'Ids storefront',
        'currency' => 'SAR',
        'timezone' => 'Asia/Riyadh',
    ]);

    app(CommerceStore::class)->upsertOrders(
        (string) $workspace->scenario->workspace->getKey(),
        (string) $store->getKey(),
        [new OrderRecord(
            externalId: 'o-ids-1',
            paymentState: PaymentState::Paid,
            fulfilmentState: FulfilmentState::Delivered,
            currency: 'SAR',
            totalMinor: 12_000,
            placedAt: new DateTimeImmutable('-3 days'),
            attribution: OrderAttribution::none(),
            refundedMinor: 0,
            customerExternalId: 'cust-1',
            items: [],
            refunds: [],
        )],
        RevenueSource::Salla,
        MetricSource::Synced,
    );

    DB::table('leads')->insert([
        'id' => (string) Str::uuid7(),
        'workspace_id' => (string) $workspace->scenario->workspace->getKey(),
        'client_id' => (string) $client->getKey(),
        'source_kind' => 'form_submission',
        'stage' => 'new',
        'attribution_evidence' => 'none',
        'received_at' => (new DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // One report that has every kind of section a client reads, across every client: the widest surface there is.
    $everything = (string) test()->actingAs($workspace->scenario->owner)
        ->postJson('/api/reports', [
            'name' => 'Everything',
            'audience' => 'client_safe',
            'window' => 'last_28_days',
            'comparison' => 'previous_period',
            'sections' => [
                ['kind' => 'scorecard', 'metrics' => ['spend', 'clicks']],
                ['kind' => 'trend', 'metrics' => ['spend']],
                ['kind' => 'breakdown', 'metrics' => ['spend'], 'dimension' => 'campaign'],
                ['kind' => 'breakdown', 'metrics' => ['spend'], 'dimension' => 'provider'],
                ['kind' => 'table', 'metrics' => ['spend', 'clicks'], 'dimension' => 'campaign'],
            ],
        ])->assertCreated()->json('data.id');

    return ['workspace' => $workspace, 'report' => $everything, 'client' => $client, 'store' => $store];
}

/** @param array{workspace: SyncedWorkspace, report: string, client: Client, store: Store} $setup */
function commerceReport(array $setup): string
{
    return (string) test()->actingAs($setup['workspace']->scenario->owner)
        ->postJson('/api/reports', [
            'name' => 'Commerce and leads',
            'client_id' => (string) $setup['client']->getKey(),
            'audience' => 'client_safe',
            'window' => 'last_30_days',
            'comparison' => 'previous_period',
            'sections' => [
                ['kind' => 'store', 'revenue_source' => 'salla'],
                ['kind' => 'lead'],
            ],
        ])->assertCreated()->json('data.id');
}

function linkFor(SyncedWorkspace $workspace, string $report, ?string $snapshot = null): string
{
    return (string) test()->actingAs($workspace->scenario->owner)
        ->postJson("/api/reports/{$report}/shares", ['days' => 7, ...($snapshot === null ? [] : ['snapshot_id' => $snapshot])])
        ->assertCreated()->json('meta.token');
}

describe('a live shared report', function (): void {
    it('carries no campaign, ad group, account, client, project, workspace or report id', function (): void {
        $setup = idsScenario();
        $token = linkFor($setup['workspace'], $setup['report']);

        $response = test()->getJson("/api/shared-reports/{$token}")->assertOk();

        // The report really does contain campaign rows, so the absence below means something.
        expect($response->json('data.report.sections.2.rows'))->not->toBeEmpty();

        assertNoInternalIds((string) $response->getContent());
    });

    it('proves the fixture is not vacuous: the agency\'s own render does carry the ids', function (): void {
        $setup = idsScenario();

        $internal = (string) test()->actingAs($setup['workspace']->scenario->owner)
            ->getJson("/api/reports/{$setup['report']}")->assertOk()->getContent();

        expect(preg_match(UUID_SHAPE, $internal))->toBe(1);
    });

    it('carries no store, client or lead id in its commerce and lead sections', function (): void {
        $setup = idsScenario();
        $token = linkFor($setup['workspace'], commerceReport($setup));

        $response = test()->getJson("/api/shared-reports/{$token}")->assertOk();

        expect($response->json('data.report.sections.0.store_detail.daily'))->not->toBeEmpty();

        assertNoInternalIds((string) $response->getContent());
    });

    it('keeps a row\'s identity stable within a link and different between two', function (): void {
        $setup = idsScenario();
        $a = linkFor($setup['workspace'], $setup['report']);
        $b = linkFor($setup['workspace'], $setup['report']);

        $keys = static fn (string $token): array => array_column(
            test()->getJson("/api/shared-reports/{$token}")->json('data.report.sections.2.rows'),
            'key',
        );

        expect($keys($a))->toBe($keys($a))
            ->and($keys($a))->not->toBe($keys($b))
            ->and(count(array_unique($keys($a))))->toBe(count($keys($a)));
    });
});

describe('a frozen shared report', function (): void {
    it('carries no internal id either, though the stored payload was rendered with them', function (): void {
        $setup = idsScenario();

        $snapshot = (string) test()->actingAs($setup['workspace']->scenario->owner)
            ->postJson("/api/reports/{$setup['report']}/snapshots", ['label' => 'Frozen'])->json('data.id');

        // What is stored is the render as the agency sees it...
        expect(preg_match(UUID_SHAPE, (string) DB::table('report_snapshots')->value('payload')))->toBe(1);

        // ...and what a visitor receives is not.
        $token = linkFor($setup['workspace'], $setup['report'], $snapshot);

        assertNoInternalIds((string) test()->getJson("/api/shared-reports/{$token}")->assertOk()->getContent());
    });
});

describe('the agency\'s own exports of a client-safe report', function (): void {
    it('has no internal id in its CSV', function (): void {
        $setup = idsScenario();

        $csv = (string) test()->actingAs($setup['workspace']->scenario->owner)
            ->get("/api/reports/{$setup['report']}/csv")->assertOk()->getContent();

        assertNoInternalIds($csv);
    });
});

describe('the filters that were already opaque', function (): void {
    it('still narrow by a key, and still refuse a raw id or another link\'s key', function (): void {
        $setup = idsScenario();
        $first = linkFor($setup['workspace'], $setup['report']);
        $second = linkFor($setup['workspace'], $setup['report']);

        $all = test()->getJson("/api/shared-reports/{$first}")->assertOk()->json('data');
        $options = $all['filters']['available']['campaigns'];

        expect($options)->not->toBe([]);

        $key = $options[0]['key'];
        $narrow = test()->getJson("/api/shared-reports/{$first}?campaign={$key}&refine=1")->assertOk()->json('data');

        expect($narrow['filters']['applied']['campaigns'])->toBe([$key]);
        assertNoInternalIds((string) json_encode($narrow));

        $realId = (string) DB::table('campaigns')->value('id');

        test()->getJson("/api/shared-reports/{$first}?campaign={$realId}")->assertUnprocessable();
        test()->getJson("/api/shared-reports/{$second}?campaign={$key}")->assertUnprocessable();
    });
});
