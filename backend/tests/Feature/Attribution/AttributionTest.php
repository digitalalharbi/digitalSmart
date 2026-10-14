<?php

declare(strict_types=1);

use App\Application\Commerce\Contract\CommerceStore;
use App\Application\Commerce\DTO\CustomerRecord;
use App\Application\Commerce\DTO\OrderRecord;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Access\SystemRole;
use App\Domain\Advertising\MetricSource;
use App\Domain\Commerce\FulfilmentState;
use App\Domain\Commerce\OrderAttribution;
use App\Domain\Commerce\PaymentState;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Integration\ConnectionOwner;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\Campaign;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\DailyMetric;
use App\Infrastructure\Persistence\Eloquent\Model\ExternalAccount;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\Store;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Attribution
|--------------------------------------------------------------------------
|
| The surface that turns "somebody bought something" into "this campaign earned the money", which is
| the most quotable claim this product makes and the easiest one to make indefensibly.
|
| So the tests are mostly about what the answer refuses to contain: a model it cannot compute, a share
| presented as a total, a ROAS across two currencies, a campaign name matched loosely, and spend the
| orders never mentioned.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project, store: Store} */
function attributionScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    /** @var Store $store */
    $store = Store::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'client_id' => $client->getKey(),
        'provider' => Provider::Salla->value,
        'external_id' => 'store-attribution-1',
        'name' => 'Noon Retail storefront',
        'currency' => 'SAR',
        'timezone' => 'Asia/Riyadh',
    ]);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project, 'store' => $store];
}

/**
 * An order carrying whatever attribution the test is about.
 *
 * @param  array<string, mixed>  $attribution
 */
function attributedOrder(
    string $id,
    int $total,
    array $attribution = [],
    string $placedAt = '2026-10-10 10:00:00',
    ?string $customer = null,
    string $currency = 'SAR',
): OrderRecord {
    return new OrderRecord(
        externalId: $id,
        paymentState: PaymentState::Paid,
        fulfilmentState: FulfilmentState::Delivered,
        currency: $currency,
        totalMinor: $total,
        placedAt: new DateTimeImmutable($placedAt),
        attribution: OrderAttribution::from(
            source: $attribution['source'] ?? null,
            medium: $attribution['medium'] ?? null,
            campaign: $attribution['campaign'] ?? null,
            clickIdentifier: $attribution['click'] ?? null,
            couponCode: $attribution['coupon'] ?? null,
            referrer: $attribution['referrer'] ?? null,
        ),
        customerExternalId: $customer,
    );
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project, store: Store}  $setup
 * @param  list<OrderRecord>  $orders
 * @param  list<CustomerRecord>  $customers
 */
function writeAttributedOrders(array $setup, array $orders, array $customers = []): void
{
    $store = app(CommerceStore::class);
    $workspaceId = (string) $setup['scenario']->workspace->getKey();
    $storeId = (string) $setup['store']->getKey();

    if ($customers !== []) {
        $store->upsertCustomers($workspaceId, $storeId, $customers);
    }

    $store->upsertOrders($workspaceId, $storeId, $orders, RevenueSource::Salla, MetricSource::Synced);
}

/**
 * Spend on an ad campaign of a given name, so the join has something to match.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project, store: Store}  $setup
 */
function spendOnCampaign(
    array $setup,
    string $name,
    int $spendMinor,
    string $currency = 'SAR',
    MetricSource $source = MetricSource::Synced,
): Campaign {
    $workspaceId = $setup['scenario']->workspace->getKey();

    /*
     * A real provider connection, through the domain path the application uses. An external account
     * belongs to one, and the column is not nullable — correctly, because an account nobody
     * authorised is an account nothing can read.
     */
    $connections = app(ProviderConnectionRepository::class);
    $connection = $connections->agencyConnection(new WorkspaceId((string) $workspaceId), Provider::Meta);

    if (! $connection instanceof ProviderConnection) {
        $credentials = [];

        foreach (Provider::Meta->requiredCredentialKeys() as $key) {
            $credentials[$key] = Credentials::SANDBOX_TOKEN;
        }

        $connection = ProviderConnection::register(
            id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
            workspaceId: new WorkspaceId((string) $workspaceId),
            provider: Provider::Meta,
            owner: ConnectionOwner::agency(),
            name: 'SANDBOX — attribution test',
            credentials: new Credentials($credentials),
        );

        $connections->save($connection);
    }

    /** @var ExternalAccount $account */
    $account = ExternalAccount::query()->firstOrCreate(
        ['workspace_id' => $workspaceId, 'external_id' => 'act_attribution'],
        [
            'provider_connection_id' => $connection->id->value,
            'provider' => Provider::Meta->value,
            'name' => 'Attribution test account',
            'currency' => $currency,
            'timezone' => 'Asia/Riyadh',
            'status' => 'active',
        ],
    );

    /** @var Campaign $campaign */
    $campaign = Campaign::query()->create([
        'workspace_id' => $workspaceId,
        'external_account_id' => $account->getKey(),
        'provider' => Provider::Meta->value,
        'external_id' => 'camp-'.md5($name),
        'name' => $name,
        'currency' => $currency,
    ]);

    DailyMetric::query()->create([
        'workspace_id' => $workspaceId,
        'external_account_id' => $account->getKey(),
        'provider' => Provider::Meta->value,
        'entity_type' => 'campaign',
        'entity_id' => $campaign->getKey(),
        'external_entity_id' => $campaign->getAttribute('external_id'),
        'campaign_id' => $campaign->getKey(),
        'date' => '2026-10-10',
        'currency' => $currency,
        'spend_minor' => $spendMinor,
        'source' => $source->value,
    ]);

    return $campaign;
}

/**
 * One attribution answer.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project, store: Store}  $setup
 * @param  array<string, string|int>  $params
 * @return array<string, mixed>
 */
function fetchAttribution(array $setup, array $params = []): array
{
    /** @var array<string, mixed> $data */
    $data = test()->actingAs($setup['scenario']->owner)
        ->getJson('/api/attribution?'.http_build_query([
            'model' => 'last_recorded',
            'revenue_source' => 'salla',
            'from' => '2026-10-01',
            'until' => '2026-10-31',
            ...$params,
        ]))
        ->assertOk()
        ->json('data');

    return $data;
}

describe('naming the model', function (): void {
    it('refuses to answer without one', function (): void {
        $setup = attributionScenario();

        /*
         * No default, deliberately. A default would be this product deciding, quietly, which campaign
         * a client is told earned their month.
         */
        $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/attribution?revenue_source=salla')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'model_required');
    });

    it('refuses to answer without a revenue authority either', function (): void {
        $setup = attributionScenario();

        $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/attribution?model=last_recorded')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'revenue_source_required');
    });

    it('refuses a model it cannot compute, and says what would unblock it', function (): void {
        $setup = attributionScenario();

        /*
         * The refusal this phase exists for. Computing something shaped like time-decay from a single
         * recorded touch would produce a number named after a model it is not, and nobody downstream
         * could tell.
         */
        $response = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/attribution?model=time_decay&revenue_source=salla')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'model_not_available');

        expect($response->json('missing'))->toContain('not available to this product')
            ->and($response->json('would_unblock'))->toContain('BigQuery')
            ->and($response->json('available'))->toBe(['last_recorded', 'first_recorded', 'paid_last_click']);
    });

    it('publishes the catalogue, including the models it cannot compute', function (): void {
        $setup = attributionScenario();

        /** @var list<array<string, mixed>> $models */
        $models = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/attribution/models')
            ->assertOk()
            ->json('data');

        $byModel = [];

        foreach ($models as $model) {
            $byModel[(string) $model['model']] = $model;
        }

        /*
         * Offered and explained rather than absent. A missing option gets asked for again every
         * quarter; one that says what it needs does not.
         */
        expect($byModel)->toHaveCount(6)
            ->and($byModel['linear']['is_available'])->toBeFalse()
            ->and($byModel['linear']['would_unblock'])->toContain('first-party tracking')
            ->and($byModel['last_recorded']['is_modelled'])->toBeFalse()
            ->and($byModel['first_recorded']['is_modelled'])->toBeTrue();
    });
});

describe('what the answer admits', function (): void {
    it('says a modelled figure is modelled', function (): void {
        $setup = attributionScenario();
        writeAttributedOrders($setup, [
            attributedOrder('o-1', 100_000, ['source' => 'meta', 'medium' => 'cpc', 'campaign' => 'autumn']),
        ]);

        $recorded = fetchAttribution($setup);
        $modelled = fetchAttribution($setup, ['model' => 'first_recorded']);

        expect($recorded['is_modelled'])->toBeFalse()
            ->and($modelled['is_modelled'])->toBeTrue();

        // And the caveat says so in words, beside the figures rather than in documentation.
        expect(implode(' ', $modelled['caveats']))->toContain('is a model, not a measurement');
    });

    it('states the revenue no campaign can be credited with', function (): void {
        $setup = attributionScenario();
        writeAttributedOrders($setup, [
            attributedOrder('o-1', 300_000, ['source' => 'meta', 'medium' => 'cpc', 'campaign' => 'autumn']),
            // Direct, organic, a returning customer, a stripped parameter — all land here legitimately.
            attributedOrder('o-2', 700_000),
        ]);

        $coverage = fetchAttribution($setup)['coverage'];

        /*
         * The number a reader most needs and least expects. Without it the 300,000 gets quoted as the
         * month, and the 700,000 is silently divided among the campaigns by whoever reads the chart.
         */
        expect($coverage['revenue_minor'])->toBe(1_000_000)
            ->and($coverage['attributed_revenue_minor'])->toBe(300_000)
            ->and($coverage['unattributed_revenue_minor'])->toBe(700_000)
            ->and((float) $coverage['revenue_coverage'])->toBe(30.0)
            ->and($coverage['is_reportable'])->toBeFalse();
    });

    it('reports the weakest evidence behind the figure, not the average', function (): void {
        $setup = attributionScenario();
        writeAttributedOrders($setup, [
            attributedOrder('o-1', 900_000, ['click' => 'gclid-1', 'source' => 'google', 'medium' => 'cpc', 'campaign' => 'autumn']),
            attributedOrder('o-2', 100_000, ['coupon' => 'INFLUENCER10']),
        ]);

        $coverage = fetchAttribution($setup)['coverage'];

        /*
         * A set that is 90% click identifiers and 10% coupon codes is still a set containing coupon
         * codes, and a reader deciding whether to act needs the floor rather than the mean.
         */
        expect($coverage['weakest_evidence'])->toBe('coupon_code')
            // The confidence is revenue-weighted, because the reader is judging the money.
            ->and($coverage['confidence'])->toBe(89);
    });
});

describe('tying revenue to spend', function (): void {
    it('puts spend on a row whose campaign name matches exactly', function (): void {
        $setup = attributionScenario();
        spendOnCampaign($setup, 'autumn-range', 100_000);
        writeAttributedOrders($setup, [
            attributedOrder('o-1', 500_000, ['source' => 'meta', 'medium' => 'cpc', 'campaign' => 'autumn-range']),
        ]);

        $row = fetchAttribution($setup)['rows'][0];

        expect($row['label'])->toBe('autumn-range')
            ->and($row['link'])->toBe('exact_name')
            ->and($row['spend_minor'])->toBe(100_000)
            ->and((float) $row['roas'])->toBe(5.0);
    });

    it('will not match a name loosely', function (): void {
        $setup = attributionScenario();
        spendOnCampaign($setup, 'autumn-range', 100_000);
        writeAttributedOrders($setup, [
            attributedOrder('o-1', 500_000, ['source' => 'meta', 'medium' => 'cpc', 'campaign' => 'autumn-range-v2']),
        ]);

        $row = fetchAttribution($setup)['rows'][0];

        /*
         * `autumn-range` and `autumn-range-v2` are two campaigns. A report that merged them would
         * attach a budget decision to the wrong one, and the reader would never know.
         */
        expect($row['link'])->toBe('no_matching_campaign')
            ->and($row['spend_minor'])->toBeNull()
            ->and($row['roas'])->toBeNull()
            ->and($row['link_explanation'])->toContain('No campaign of this name');
    });

    it('matches a name whose case and spacing differ', function (): void {
        $setup = attributionScenario();
        spendOnCampaign($setup, 'Autumn Range', 100_000);
        writeAttributedOrders($setup, [
            attributedOrder('o-1', 500_000, ['source' => 'meta', 'medium' => 'cpc', 'campaign' => '  autumn range  ']),
        ]);

        // Case and surrounding space are typing, not identity. Nothing looser than that.
        expect(fetchAttribution($setup)['rows'][0]['link'])->toBe('exact_name');
    });

    it('refuses to divide revenue in one currency by spend in another', function (): void {
        $setup = attributionScenario();
        spendOnCampaign($setup, 'autumn-range', 100_000, 'AED');
        writeAttributedOrders($setup, [
            attributedOrder('o-1', 500_000, ['source' => 'meta', 'medium' => 'cpc', 'campaign' => 'autumn-range']),
        ]);

        $row = fetchAttribution($setup)['rows'][0];

        /*
         * A ROAS of riyals over dirhams looks entirely ordinary and means nothing. The row keeps its
         * revenue and the spend cell is empty.
         */
        expect($row['revenue_minor'])->toBe(500_000)
            ->and($row['spend_minor'])->toBeNull()
            ->and($row['roas'])->toBeNull();
    });

    it('reports spend the orders never mentioned', function (): void {
        $setup = attributionScenario();
        spendOnCampaign($setup, 'autumn-range', 100_000);
        spendOnCampaign($setup, 'winter-teaser', 220_000);
        writeAttributedOrders($setup, [
            attributedOrder('o-1', 500_000, ['source' => 'meta', 'medium' => 'cpc', 'campaign' => 'autumn-range']),
        ]);

        /*
         * Half the question an agency is asked, and the half a report listing only winning campaigns
         * leaves out: the 220,000 went somewhere the orders never mentioned, which is a tagging
         * problem with a name rather than a gap.
         */
        expect(fetchAttribution($setup)['unmatched_spend_minor'])->toBe(220_000);
    });

    it('says why a click identifier cannot be placed on a campaign row', function (): void {
        $setup = attributionScenario();
        writeAttributedOrders($setup, [
            attributedOrder('o-1', 500_000, ['click' => 'fbclid-abc']),
        ]);

        $row = fetchAttribution($setup)['rows'][0];

        /*
         * Genuinely attributed — the click reached the checkout — and genuinely unplaceable, because
         * resolving a click id to a campaign needs the platform's click-level API.
         */
        expect($row['link'])->toBe('click_not_resolved')
            ->and($row['joins_spend'])->toBeFalse()
            ->and($row['link_explanation'])->toContain('click-level API');
    });

    it('never splits a channel-only order across the channel\'s campaigns', function (): void {
        $setup = attributionScenario();
        spendOnCampaign($setup, 'autumn-range', 100_000);
        writeAttributedOrders($setup, [
            // A source and medium, no campaign. Names a channel at best.
            attributedOrder('o-1', 400_000, ['source' => 'meta', 'medium' => 'cpc']),
        ]);

        $data = fetchAttribution($setup);
        $row = $data['rows'][0];

        expect($row['link'])->toBe('channel_only')
            ->and($row['spend_minor'])->toBeNull()
            // It counts as attributed, and as *not* naming a campaign. Both are true.
            ->and($data['coverage']['attributed_conversions'])->toBe(1)
            ->and($data['coverage']['campaign_named_conversions'])->toBe(0);
    });
});

describe('the paid-only view', function (): void {
    it('counts a platform click and a paid medium, and leaves organic out', function (): void {
        $setup = attributionScenario();
        writeAttributedOrders($setup, [
            attributedOrder('o-1', 100_000, ['click' => 'gclid-1']),
            attributedOrder('o-2', 200_000, ['source' => 'meta', 'medium' => 'cpc', 'campaign' => 'autumn']),
            attributedOrder('o-3', 400_000, ['source' => 'newsletter', 'medium' => 'email', 'campaign' => 'october-send']),
        ]);

        $paid = fetchAttribution($setup, ['model' => 'paid_last_click']);

        /*
         * The question an agency is actually asked is "what did the money we spent produce", and an
         * e-mail send carrying a UTM set answers a different one. The newsletter order is not
         * unattributed — it is attributed to something this model does not count, so it still raises
         * the denominator and the report shows how much it leaves out.
         */
        expect($paid['coverage']['attributed_revenue_minor'])->toBe(300_000)
            ->and($paid['coverage']['revenue_minor'])->toBe(700_000);
    });

    it('does not treat an unrecognised medium as paid', function (): void {
        $setup = attributionScenario();
        writeAttributedOrders($setup, [
            attributedOrder('o-1', 100_000, ['source' => 'partner', 'medium' => 'carrier-pigeon', 'campaign' => 'autumn']),
        ]);

        // A medium nobody recognises is not evidence that somebody paid for the traffic.
        expect(fetchAttribution($setup, ['model' => 'paid_last_click'])['coverage']['attributed_revenue_minor'])
            ->toBe(0);
    });
});

describe('the first recorded conversion', function (): void {
    it('credits the enquiry that came before the order', function (): void {
        $setup = attributionScenario();

        // An enquiry in September, tagged with the campaign that found this customer.
        DB::table('leads')->insert([
            'id' => (string) Illuminate\Support\Str::uuid7(),
            'workspace_id' => $setup['scenario']->workspace->getKey(),
            'client_id' => $setup['client']->getKey(),
            'source_kind' => 'form_submission',
            'stage' => 'won',
            'attribution_evidence' => 'utm_parameters',
            'utm_source' => 'meta',
            'utm_campaign' => 'autumn-prospecting',
            'phone_key' => '966555001122',
            'received_at' => '2026-09-28 09:00:00',
            'closed_at' => '2026-10-10 09:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        writeAttributedOrders(
            $setup,
            [attributedOrder('o-1', 500_000, ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'brand-search'], customer: 'cust-1')],
            [new CustomerRecord(externalId: 'cust-1', phone: '0555001122', name: 'Layla')],
        );

        $recorded = fetchAttribution($setup);
        $first = fetchAttribution($setup, ['model' => 'first_recorded', 'lookback_days' => 60]);

        /*
         * The whole point of the model, and the whole point of the comparison surface: the same
         * 500,000 belongs to brand-search or to autumn-prospecting depending on a choice nobody can
         * make from the data. Both figures are correct under their own definition.
         */
        expect($recorded['rows'][0]['label'])->toBe('brand-search')
            ->and($first['rows'][0]['label'])->toBe('autumn-prospecting');
    });

    it('respects the lookback window', function (): void {
        $setup = attributionScenario();

        DB::table('leads')->insert([
            'id' => (string) Illuminate\Support\Str::uuid7(),
            'workspace_id' => $setup['scenario']->workspace->getKey(),
            'client_id' => $setup['client']->getKey(),
            'source_kind' => 'form_submission',
            'stage' => 'new',
            'attribution_evidence' => 'utm_parameters',
            'utm_campaign' => 'autumn-prospecting',
            'phone_key' => '966555001122',
            'received_at' => '2026-09-28 09:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        writeAttributedOrders(
            $setup,
            [attributedOrder('o-1', 500_000, ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'brand-search'], customer: 'cust-1')],
            [new CustomerRecord(externalId: 'cust-1', phone: '0555001122', name: 'Layla')],
        );

        // Seven days back from 10 October does not reach 28 September, so the order is the first thing known.
        $narrow = fetchAttribution($setup, ['model' => 'first_recorded', 'lookback_days' => 7]);

        expect($narrow['rows'][0]['label'])->toBe('brand-search')
            // And the window is published, because a different one credits different campaigns.
            ->and($narrow['lookback_days'])->toBe(7);
    });

    it('credits a first-time customer with what their own order carried', function (): void {
        $setup = attributionScenario();
        writeAttributedOrders(
            $setup,
            [attributedOrder('o-1', 500_000, ['source' => 'meta', 'medium' => 'cpc', 'campaign' => 'autumn'], customer: 'cust-1')],
            [new CustomerRecord(externalId: 'cust-1', phone: '0555009999', name: 'New')],
        );

        /*
         * No earlier conversion, so this order *is* their first recorded one. A model that reported
         * nothing here would understate every campaign that wins new business — which is most of what
         * an agency is paid for.
         */
        expect(fetchAttribution($setup, ['model' => 'first_recorded'])['rows'][0]['label'])->toBe('autumn');
    });
});

describe('comparing the models', function (): void {
    it('reports where the choice of model changes the figure', function (): void {
        $setup = attributionScenario();

        DB::table('leads')->insert([
            'id' => (string) Illuminate\Support\Str::uuid7(),
            'workspace_id' => $setup['scenario']->workspace->getKey(),
            'client_id' => $setup['client']->getKey(),
            'source_kind' => 'form_submission',
            'stage' => 'new',
            'attribution_evidence' => 'utm_parameters',
            'utm_campaign' => 'autumn-prospecting',
            'phone_key' => '966555001122',
            'received_at' => '2026-10-02 09:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        writeAttributedOrders(
            $setup,
            [attributedOrder('o-1', 500_000, ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'brand-search'], customer: 'cust-1')],
            [new CustomerRecord(externalId: 'cust-1', phone: '0555001122', name: 'Layla')],
        );

        /** @var array<string, mixed> $data */
        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/attribution/comparison?'.http_build_query([
                'revenue_source' => 'salla',
                'from' => '2026-10-01',
                'until' => '2026-10-31',
            ]))
            ->assertOk()
            ->json('data');

        /*
         * The statement the surface leads with: on this client's own campaigns, choosing a model moves
         * the figure completely. Neither number should be quoted as what the campaign earned.
         */
        expect($data['models'])->toBe(['last_recorded', 'first_recorded', 'paid_last_click'])
            ->and($data['material_disagreements'])->toBeGreaterThan(0);

        $byLabel = [];

        foreach ($data['campaigns'] as $row) {
            $byLabel[(string) $row['label']] = $row;
        }

        /*
         * Zero rather than null for the model that credited the campaign nothing. The breakdown was
         * not truncated, so that model saw every campaign — which makes "half a million under one
         * model, nothing under the other" a real and total disagreement rather than a gap in the data.
         */
        expect($byLabel['brand-search']['revenue_minor_by_model']['last_recorded'])->toBe(500_000)
            ->and($byLabel['brand-search']['revenue_minor_by_model']['first_recorded'])->toBe(0)
            ->and((float) $byLabel['brand-search']['spread_percent'])->toBe(100.0)
            ->and($byLabel['brand-search']['is_material'])->toBeTrue()
            ->and($byLabel['autumn-prospecting']['revenue_minor_by_model']['first_recorded'])->toBe(500_000);
    });

    it('will not call a truncated breakdown a disagreement', function (): void {
        $setup = attributionScenario();

        // Three campaigns, asked for one row. Every model's breakdown is then truncated.
        writeAttributedOrders($setup, [
            attributedOrder('o-1', 300_000, ['source' => 'meta', 'medium' => 'cpc', 'campaign' => 'first']),
            attributedOrder('o-2', 200_000, ['source' => 'meta', 'medium' => 'cpc', 'campaign' => 'second']),
            attributedOrder('o-3', 100_000, ['source' => 'meta', 'medium' => 'cpc', 'campaign' => 'third']),
        ]);

        /** @var array<string, mixed> $data */
        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/attribution/comparison?'.http_build_query([
                'revenue_source' => 'salla',
                'from' => '2026-10-01',
                'until' => '2026-10-31',
                'limit' => 1,
            ]))
            ->assertOk()
            ->json('data');

        /*
         * All three models agree completely here; the only reason a campaign is missing from a model's
         * rows is the row limit. Recording that as a zero would manufacture a 100% disagreement out of
         * a pagination artefact, and the surface would lead with it.
         */
        expect($data['material_disagreements'])->toBe(0);
    });
});

describe('access', function (): void {
    it('requires authentication', function (): void {
        $this->getJson('/api/attribution?model=last_recorded&revenue_source=salla')->assertUnauthorized();
        $this->getJson('/api/attribution/models')->assertUnauthorized();
    });

    it('refuses a creative', function (): void {
        $setup = attributionScenario();
        $creative = $setup['scenario']->addMember(SystemRole::Creative);

        /*
         * Attribution is a view of a client's money. A role without the analytics permission has no
         * business reading it, and the refusal is server-side rather than a hidden menu item.
         */
        $this->actingAs($creative)
            ->getJson('/api/attribution?model=last_recorded&revenue_source=salla')
            ->assertForbidden();
    });

    it('does not reach another workspace', function (): void {
        $mine = attributionScenario();
        $theirs = attributionScenario();

        writeAttributedOrders($theirs, [
            attributedOrder('o-1', 900_000, ['source' => 'meta', 'medium' => 'cpc', 'campaign' => 'theirs']),
        ]);

        $data = fetchAttribution($mine);

        expect($data['rows'])->toBe([])
            ->and($data['coverage']['conversions'])->toBe(0);
    });

    it('hides a client the actor cannot reach', function (): void {
        $setup = attributionScenario();
        writeAttributedOrders($setup, [
            attributedOrder('o-1', 500_000, ['source' => 'meta', 'medium' => 'cpc', 'campaign' => 'autumn']),
        ]);

        // A buyer with the permission and no project assignment: authorised to use the page, with
        // nothing on it. Server-side, not hidden in the interface.
        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);

        /** @var array<string, mixed> $data */
        $data = $this->actingAs($buyer)
            ->getJson('/api/attribution?'.http_build_query([
                'model' => 'last_recorded',
                'revenue_source' => 'salla',
                'from' => '2026-10-01',
                'until' => '2026-10-31',
            ]))
            ->assertOk()
            ->json('data');

        expect($data['coverage']['conversions'])->toBe(0);
    });
});
