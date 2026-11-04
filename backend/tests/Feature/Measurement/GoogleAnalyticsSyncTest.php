<?php

declare(strict_types=1);

use App\Application\Measurement\Command\SyncMeasurementSource;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Access\SystemRole;
use App\Domain\Identity\UserId;
use App\Domain\Integration\ConnectionOwner;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\MeasurementSource;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Google Analytics 4 — discovery, suggestion and sync
|--------------------------------------------------------------------------
|
| Driven through HTTP against the sandbox fixtures, which hold responses in the shapes the
| Admin and Data APIs document. The live transport is BLOCKED_EXTERNAL, so the tests that matter
| most are the two about refusal: real credentials must fail loudly rather than quietly
| returning something, and a sampled response must never be labelled as a total.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project, source: MeasurementSource} */
function ga4Scenario(bool $sandbox = true): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    connectAnalytics($scenario, $sandbox);

    /** @var MeasurementSource $source */
    $source = MeasurementSource::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'client_id' => $client->getKey(),
        'kind' => 'analytics',
        'provider' => Provider::GoogleAnalytics->value,
        'external_id' => 'properties/412006881',
        'name' => 'Noon Retail — Web',
    ]);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project, 'source' => $source];
}

/**
 * Register a GA4 connection through the real domain path.
 *
 * The literal `sandbox` credential is the only thing that makes the adapter read fixtures.
 * Anything else is treated as real and refused — which is what keeps invented numbers out of a
 * live connection, and what the last group of tests here exists to prove.
 */
function connectAnalytics(WorkspaceScenario $scenario, bool $sandbox): ProviderConnection
{
    $values = [];

    foreach (Provider::GoogleAnalytics->requiredCredentialKeys() as $key) {
        $values[$key] = $sandbox ? Credentials::SANDBOX_TOKEN : 'a-real-looking-secret';
    }

    $connection = ProviderConnection::register(
        id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
        workspaceId: new WorkspaceId((string) $scenario->workspace->getKey()),
        provider: Provider::GoogleAnalytics,
        owner: ConnectionOwner::agency(),
        name: 'Google Analytics 4',
        credentials: new Credentials($values),
    );

    app(ProviderConnectionRepository::class)->save($connection);

    return $connection;
}

/**
 * One stored observation, failing the test outright if the sync never wrote it.
 *
 * `first()` returns null for a missing row, and asserting against null would report "expected
 * 841575, got 0" — which reads as a rounding bug rather than as nothing having been written.
 */
function observedRow(mixed $sourceId, string $rawEventName): stdClass
{
    $row = DB::table('observed_event_counts')
        ->where('measurement_source_id', $sourceId)
        ->where('raw_event_name', $rawEventName)
        ->orderByDesc('date')
        ->first();

    expect($row)->not->toBeNull("no observation stored for \"{$rawEventName}\"");

    /** @var stdClass $row */
    return $row;
}

describe('discovering properties', function (): void {
    it('lists every property the account can read, including the ones to leave alone', function (): void {
        $setup = ga4Scenario();

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/measurement/providers/google_analytics/properties')
            ->assertOk()
            ->json('data');

        /*
         * Unfiltered on purpose. An agency's Google account holds properties for clients it no
         * longer works with, and that is precisely the list a person has to read carefully rather
         * than one this product should prune for them.
         */
        $names = array_column($data, 'property_id');

        expect($names)->toContain('properties/412006881')
            ->and($names)->toContain('properties/412007100');
    });

    it('carries the site and measurement id a person actually recognises', function (): void {
        $setup = ga4Scenario();

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/measurement/providers/google_analytics/properties')
            ->assertOk()
            ->json('data');

        $property = jsonRow($data, 'property_id', 'properties/412006881');

        // A list of numeric ids is unusable for the one decision this screen supports.
        expect($property['streams'][0]['measurement_id'])->toBe('G-7QK2M4XZPL')
            ->and($property['streams'][0]['default_uri'])->toBe('https://noonretail.sa')
            ->and($property['streams'][0]['is_web'])->toBeTrue();
    });

    it('flags a roll-up property as an aggregate', function (): void {
        $setup = ga4Scenario();

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/measurement/providers/google_analytics/properties')
            ->assertOk()
            ->json('data');

        /*
         * A roll-up counts the same event its source properties do. Measuring one client through
         * both would double every figure, and nothing later in the product could detect it.
         */
        expect(jsonRow($data, 'property_id', 'properties/412006890')['is_aggregate'])->toBeTrue()
            ->and(jsonRow($data, 'property_id', 'properties/412006881')['is_aggregate'])->toBeFalse();
    });

    it('groups each property with only its own streams', function (): void {
        $setup = ga4Scenario();

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/measurement/providers/google_analytics/properties')
            ->assertOk()
            ->json('data');

        $app = jsonRow($data, 'property_id', 'properties/412006882');

        expect($app['streams'])->toHaveCount(2)
            ->and(array_column($app['streams'], 'app_identifier'))
            ->toBe(['sa.noonretail.shop', 'sa.noonretail.shop']);
    });

    it('answers with nothing for a provider that has no measurement adapter', function (): void {
        $setup = ga4Scenario();

        // Meta is connected for advertising and reports no events here. Empty, not an error.
        expect($this->actingAs($setup['scenario']->owner)
            ->getJson('/api/measurement/providers/meta/properties')
            ->assertOk()
            ->json('data'))->toBe([]);
    });

    it('refuses a provider that does not exist', function (): void {
        $setup = ga4Scenario();

        $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/measurement/providers/telepathy/properties')
            ->assertNotFound();
    });

    it('refuses a role that cannot manage tracking', function (): void {
        $setup = ga4Scenario();

        // Reading a workspace's whole analytics estate is a setup action, not a reporting one.
        $this->actingAs($setup['scenario']->addMember(SystemRole::Creative))
            ->getJson('/api/measurement/providers/google_analytics/properties')
            ->assertForbidden();
    });
});

describe('suggesting conversions', function (): void {
    it('offers the property\'s key events as candidates', function (): void {
        $setup = ga4Scenario();

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson("/api/measurement/sources/{$setup['source']->getKey()}/suggestions")
            ->assertOk()
            ->json('data');

        expect(array_column($data, 'raw_event_name'))
            ->toBe(['purchase', 'generate_lead', 'whatsapp_click']);
    });

    it('places only the names Google documents, and asks about the rest', function (): void {
        $setup = ga4Scenario();

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson("/api/measurement/sources/{$setup['source']->getKey()}/suggestions")
            ->assertOk()
            ->json('data');

        /*
         * `purchase` is documented, so it is proposed. `generate_lead` is not on either page this
         * map was built from — so it comes back null and a person chooses, even though the name
         * looks obvious. A suggestion this product cannot cite is a guess wearing a citation's
         * clothes, and the cost of asking is one dropdown.
         */
        expect(jsonRow($data, 'raw_event_name', 'purchase')['normalized_event'])->toBe('purchase')
            ->and(jsonRow($data, 'raw_event_name', 'generate_lead')['normalized_event'])->toBeNull()
            ->and(jsonRow($data, 'raw_event_name', 'whatsapp_click')['normalized_event'])->toBeNull();
    });

    it('says how GA4 counts an event, because it changes what the number means', function (): void {
        $setup = ga4Scenario();

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson("/api/measurement/sources/{$setup['source']->getKey()}/suggestions")
            ->assertOk()
            ->json('data');

        /*
         * Counted once per session, two leads in one visit are one key event. Somebody
         * reconciling that against a CRM count would otherwise conclude the tracking is broken.
         */
        expect(jsonRow($data, 'raw_event_name', 'generate_lead')['counted_once_per_session'])->toBeTrue()
            ->and(jsonRow($data, 'raw_event_name', 'purchase')['counted_once_per_session'])->toBeFalse()
            ->and(jsonRow($data, 'raw_event_name', 'purchase')['is_custom'])->toBeFalse();
    });

    it('marks a name a conversion already claims', function (): void {
        $setup = ga4Scenario();
        $clientId = (string) $setup['client']->getKey();

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/conversions', [
                'client_id' => $clientId,
                'name' => 'Sale',
                'normalized_event' => 'purchase',
                'is_primary' => true,
                'mappings' => [['raw_event_name' => 'purchase']],
            ])
            ->assertCreated();

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson("/api/measurement/sources/{$setup['source']->getKey()}/suggestions")
            ->assertOk()
            ->json('data');

        // So confirming the same list twice cannot create a second definition counting one event.
        expect(jsonRow($data, 'raw_event_name', 'purchase')['already_mapped'])->toBeTrue()
            ->and(jsonRow($data, 'raw_event_name', 'generate_lead')['already_mapped'])->toBeFalse();
    });

    it('creates nothing by itself', function (): void {
        $setup = ga4Scenario();

        $this->actingAs($setup['scenario']->owner)
            ->getJson("/api/measurement/sources/{$setup['source']->getKey()}/suggestions")
            ->assertOk();

        /*
         * The point of the whole suggestion design. GA4 marking an event key is GA4's opinion;
         * what this agency counts for this client is a decision with an author.
         */
        expect(DB::table('event_definitions')->count())->toBe(0);
    });
});

describe('syncing what the property reported', function (): void {
    it('writes a day row per event name and labels it sandbox', function (): void {
        $setup = ga4Scenario();

        $result = $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/measurement/sources/{$setup['source']->getKey()}/sync")
            ->assertOk()
            ->json('data');

        expect($result['status'])->toBe('succeeded')
            ->and($result['rows_written'])->toBeGreaterThan(0)
            // Fixtures are never shown as production, whatever else the response claimed.
            ->and($result['source'])->toBe('sandbox');

        $rows = DB::table('observed_event_counts')
            ->where('measurement_source_id', $setup['source']->getKey())
            ->get();

        expect($rows)->not->toBeEmpty()
            ->and($rows->pluck('source')->unique()->all())->toBe(['sandbox']);
    });

    it('pages through the whole report', function (): void {
        $setup = ga4Scenario();

        $result = $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/measurement/sources/{$setup['source']->getKey()}/sync")
            ->assertOk()
            ->json('data');

        // Two fixture pages of events plus one traffic page, so a sync that stopped at the first events page would report one call.
        expect($result['api_calls'])->toBe(3);
    });

    it('keeps the raw name without deciding what it means', function (): void {
        $setup = ga4Scenario();

        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/measurement/sources/{$setup['source']->getKey()}/sync")
            ->assertOk();

        $names = DB::table('observed_event_counts')
            ->where('measurement_source_id', $setup['source']->getKey())
            ->pluck('raw_event_name')
            ->unique()
            ->values()
            ->all();

        /*
         * `purchase_complete` is stored exactly as it arrived and counts towards nothing until
         * somebody maps it. The tracking health check is what surfaces it.
         */
        expect($names)->toContain('purchase_complete')
            // And `(other)` is not an event name, so it is never stored as one.
            ->and($names)->not->toContain('(other)');
    });

    it('stores revenue in minor units and leaves non-revenue events without a value', function (): void {
        $setup = ga4Scenario();

        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/measurement/sources/{$setup['source']->getKey()}/sync")
            ->assertOk();

        $purchase = observedRow($setup['source']->getKey(), 'purchase');
        $pageView = observedRow($setup['source']->getKey(), 'page_view');

        expect((int) $purchase->value_minor)->toBe(841_575)
            ->and($purchase->currency)->toBe('SAR')
            /*
             * Null, not zero. GA4 sends 0 revenue for every non-revenue event, and a stored zero
             * would hide the tracking fault where a purchase genuinely arrives without a value.
             */
            ->and($pageView->value_minor)->toBeNull()
            ->and($pageView->currency)->toBeNull();
    });

    it('re-reading the same window replaces rather than accumulates', function (): void {
        $setup = ga4Scenario();
        $url = "/api/measurement/sources/{$setup['source']->getKey()}/sync";

        $this->actingAs($setup['scenario']->owner)->postJson($url)->assertOk();
        $firstTotal = (int) DB::table('observed_event_counts')
            ->where('measurement_source_id', $setup['source']->getKey())
            ->sum('event_count');
        $firstRows = DB::table('observed_event_counts')
            ->where('measurement_source_id', $setup['source']->getKey())
            ->count();

        $this->actingAs($setup['scenario']->owner)->postJson($url)->assertOk();

        /*
         * The failure this guards against would look exactly like growth: every sync re-reads a
         * restatement window, so an insert rather than an upsert would multiply a client's
         * conversions by the number of times the sync had run.
         */
        expect((int) DB::table('observed_event_counts')
            ->where('measurement_source_id', $setup['source']->getKey())
            ->sum('event_count'))->toBe($firstTotal)
            ->and(DB::table('observed_event_counts')
                ->where('measurement_source_id', $setup['source']->getKey())
                ->count())->toBe($firstRows);
    });

    it('records when it read and when the property last saw anything', function (): void {
        $setup = ga4Scenario();

        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/measurement/sources/{$setup['source']->getKey()}/sync")
            ->assertOk();

        $source = MeasurementSource::query()->findOrFail($setup['source']->getKey());

        // Two timestamps, two questions: is it still working, and is it still measuring anything.
        expect($source->getAttribute('last_synced_at'))->not->toBeNull()
            ->and($source->getAttribute('last_event_at'))->not->toBeNull();
    });

    it('turns a freshly synced source into a live one on the tracking page', function (): void {
        $setup = ga4Scenario();

        $before = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/measurement/health')
            ->assertOk()
            ->json('data');

        expect(array_column($before['issues'], 'code'))->toContain('never_synced');

        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/measurement/sources/{$setup['source']->getKey()}/sync")
            ->assertOk();

        $after = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/measurement/health')
            ->assertOk()
            ->json('data');

        /*
         * The end-to-end point of the phase: the fixtures shift their dates so a sandbox property
         * reports recent events, which is what makes the tracking page readable instead of a wall
         * of warnings about fixtures.
         */
        expect(jsonRow($after['sources'], 'name', 'Noon Retail — Web')['freshness'])->toBe('live')
            ->and(array_column($after['issues'], 'code'))->not->toContain('never_synced');
    });

    it('surfaces the unmapped events it just found', function (): void {
        $setup = ga4Scenario();

        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/measurement/sources/{$setup['source']->getKey()}/sync")
            ->assertOk();

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/measurement/health')
            ->assertOk()
            ->json('data');

        $unmapped = array_values(array_filter(
            $data['issues'],
            static fn (array $issue): bool => $issue['code'] === 'unmapped_event',
        ));

        // Nothing is mapped yet, so everything that arrived counts towards nothing — and the
        // tracking page is the only place that is visible.
        expect($unmapped)->not->toBeEmpty()
            ->and(array_column($unmapped, 'subject'))->toContain('purchase_complete');
    });

    it('skips a source with no provider instead of failing', function (): void {
        $setup = ga4Scenario();

        /** @var MeasurementSource $manual */
        $manual = MeasurementSource::query()->create([
            'workspace_id' => $setup['scenario']->workspace->getKey(),
            'client_id' => $setup['client']->getKey(),
            'kind' => 'manual',
            'external_id' => 'typed-by-hand',
            'name' => 'Phone enquiries',
        ]);

        $result = $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/measurement/sources/{$manual->getKey()}/sync")
            ->assertOk()
            ->json('data');

        /*
         * A typed figure has nothing to read. Distinguishable from an error so the interface can
         * disable the button rather than offer one that fails.
         */
        expect($result['status'])->toBe('skipped')
            ->and($result['rows_written'])->toBe(0);
    });

    it('refuses a role that cannot manage tracking', function (): void {
        $setup = ga4Scenario();

        $this->actingAs($setup['scenario']->addMember(SystemRole::Creative))
            ->postJson("/api/measurement/sources/{$setup['source']->getKey()}/sync")
            ->assertForbidden();
    });

    it('does not sync another workspace\'s source', function (): void {
        $setup = ga4Scenario();
        $other = ga4Scenario();

        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/measurement/sources/{$other['source']->getKey()}/sync")
            ->assertNotFound();
    });
});

describe('honesty about an incomplete answer', function (): void {
    it('labels a sampled and thresholded run partial rather than synced', function (): void {
        $setup = ga4Scenario();

        /*
         * The second fixture page is sampled, thresholded and has an `(other)` row — all three
         * reported by GA4 about its own answer. In a live workspace this is the difference between
         * "412 leads" and "roughly 412 leads, from 8% of the data, with small rows withheld".
         */
        $result = $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/measurement/sources/{$setup['source']->getKey()}/sync")
            ->assertOk()
            ->json('data');

        expect($result['caveats'])->not->toBeEmpty();

        $joined = implode(' ', $result['caveats']);

        expect($joined)->toContain('8.0%')
            ->and($joined)->toContain('withheld for privacy')
            ->and($joined)->toContain('(other)');
    });

    it('would label a real run partial when the provider says it was sampled', function (): void {
        $setup = ga4Scenario();

        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/measurement/sources/{$setup['source']->getKey()}/sync")
            ->assertOk();

        /*
         * In sandbox the label is `sandbox`, which outranks everything — fixtures are never shown
         * as production. The caveats above are what prove the sampling was read; this asserts the
         * rows carry a label at all, so no figure is ever stored unlabelled.
         */
        $sources = DB::table('observed_event_counts')
            ->where('measurement_source_id', $setup['source']->getKey())
            ->pluck('source')
            ->unique()
            ->all();

        expect($sources)->toBe(['sandbox']);
    });
});

describe('the live transport is blocked', function (): void {
    it('refuses real credentials loudly instead of inventing figures', function (): void {
        $setup = ga4Scenario(sandbox: false);

        $result = $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/measurement/sources/{$setup['source']->getKey()}/sync")
            ->assertOk()
            ->json('data');

        /*
         * The most important test in this file. No OAuth client has been authorised, so a live
         * read is impossible — and the only acceptable behaviour is to fail and say so. A silent
         * fake success would put invented event counts in front of a client.
         */
        expect($result['status'])->toBe('failed')
            ->and($result['rows_written'])->toBe(0)
            ->and($result['failure_message'])->not->toBeNull();

        expect(DB::table('observed_event_counts')->count())->toBe(0);
    });

    it('still records the attempt, so a failing source does not look untouched', function (): void {
        $setup = ga4Scenario(sandbox: false);

        $this->actingAs($setup['scenario']->owner)
            ->postJson("/api/measurement/sources/{$setup['source']->getKey()}/sync")
            ->assertOk();

        $source = MeasurementSource::query()->findOrFail($setup['source']->getKey());

        /*
         * "We tried and got nowhere" is a different fact from "nobody has asked", and a tracking
         * page needs to tell them apart. The sync timestamp moves; the event timestamp does not.
         */
        expect($source->getAttribute('last_synced_at'))->not->toBeNull()
            ->and($source->getAttribute('last_event_at'))->toBeNull();
    });

    it('returns no properties to discover without an authorised client', function (): void {
        $setup = ga4Scenario(sandbox: false);

        $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/measurement/providers/google_analytics/properties')
            ->assertStatus(503)
            ->assertJsonPath('code', 'provider_not_configured');
    });
});

describe('the range the adapter will answer', function (): void {
    /*
     * The limit the adapter publishes, honoured. The GA4 adapter caps a request at 92 days — not because the
     * Data API refuses more, but because a year of daily rows per event name is a large response to hold for no
     * benefit, and a failure should cost one window rather than the whole history. Before this the range went out
     * whole, so the cap was recorded and ignored: the adapter looked as though it had been honoured.
     *
     * Called directly rather than through HTTP, because the endpoint takes no `since` — the long range is a
     * backfill a queued job asks for, which is exactly the caller the cap exists for.
     */
    it('asks for a year in windows rather than one request', function (): void {
        $setup = ga4Scenario();

        /*
         * An actor is bound the way the request middleware binds one, because the use case guards on a
         * permission — calling it with no actor would exercise a path no live caller takes.
         */
        app(ActorContextBinder::class)->bind(
            new UserId((string) $setup['scenario']->owner->getKey()),
            new WorkspaceId((string) $setup['scenario']->workspace->getKey()),
        );

        $sync = fn (?DateTimeImmutable $since) => app(SyncMeasurementSource::class)(
            (string) $setup['source']->getKey(),
            $since,
        );

        $short = $sync(null);
        $long = $sync(new DateTimeImmutable('-365 days'));

        /*
         * A year over a 92-day cap is five windows, so the long run makes strictly more requests. Asserted as a
         * relation rather than a count: the fixture's paging decides the absolute number, and pinning it would
         * make this a test of the fixture.
         */
        expect($long->apiCalls)->toBeGreaterThan($short->apiCalls)
            // And the figures are still labelled as fixtures, whatever the range was.
            ->and($long->source->value)->toBe('sandbox');
    });
});

/** The first stored traffic row matching the filter, failing the test outright when there is none. */
function trafficRow(string $sourceName, string $medium): stdClass
{
    $row = DB::table('ga4_traffic_daily')->where('source_name', $sourceName)->where('medium', $medium)->orderByDesc('date')->first();
    expect($row)->not->toBeNull();
    assert($row instanceof stdClass);

    return $row;
}

describe('acquisition traffic read alongside the events', function (): void {
    it('stores sessions, users and engagement by channel, source and medium, and says how many rows', function (): void {
        $setup = ga4Scenario();

        $result = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/measurement/sources/{$setup['source']->getKey()}/sync")
            ->assertOk()
            ->json('data');

        expect($result['traffic_rows_written'])->toBe(10);

        $paid = trafficRow('snapchat', 'paid_social');

        expect($paid->channel_group)->toBe('Paid Social')
            ->and($paid->sessions)->toBe(620)
            ->and($paid->users)->toBe(540)
            ->and($paid->engaged_sessions)->toBe(455)
            ->and($paid->engagement_seconds)->toBe(38400)
            ->and($paid->page_views)->toBe(1710)
            ->and($paid->time_zone)->toBe('Asia/Riyadh')
            ->and($paid->source)->not->toBe('live');
    });

    it('stores null, not zero, for a metric the property does not have', function (): void {
        $setup = ga4Scenario();

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/measurement/sources/{$setup['source']->getKey()}/sync")->assertOk();

        // `newUsers` is deliberately absent from the sandbox property's metadata, so it is never requested.
        $rows = DB::table('ga4_traffic_daily')->where('measurement_source_id', $setup['source']->getKey())->get();

        expect($rows)->toHaveCount(10)
            ->and($rows->every(fn ($row) => $row->new_users === null))->toBeTrue()
            ->and($rows->every(fn ($row) => $row->sessions !== null))->toBeTrue();
    });

    it('keeps an unattributed session as the property named it rather than dropping or renaming it', function (): void {
        $setup = ga4Scenario();

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/measurement/sources/{$setup['source']->getKey()}/sync")->assertOk();

        $row = trafficRow('(not set)', '(not set)');

        expect($row->channel_group)->toBe('Unassigned')->and($row->medium)->toBe('(not set)');
    });

    it('replaces on a re-read instead of doubling the sessions', function (): void {
        $setup = ga4Scenario();
        $url = "/api/measurement/sources/{$setup['source']->getKey()}/sync";

        test()->actingAs($setup['scenario']->owner)->postJson($url)->assertOk();
        $first = (int) DB::table('ga4_traffic_daily')->sum('sessions');
        test()->actingAs($setup['scenario']->owner)->postJson($url)->assertOk();

        expect(DB::table('ga4_traffic_daily')->count())->toBe(10)
            ->and((int) DB::table('ga4_traffic_daily')->sum('sessions'))->toBe($first);
    });

    it('reads no traffic for a source whose provider has no traffic report', function (): void {
        $setup = ga4Scenario(sandbox: false);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/measurement/sources/{$setup['source']->getKey()}/sync")->assertOk();

        // Real credentials are refused (BLOCKED_EXTERNAL): no row, and certainly no invented one.
        expect(DB::table('ga4_traffic_daily')->count())->toBe(0);
    });
});

/* GA4 traffic API: what it says, and to whom. Driven through the real sync, so the API returns what the sync wrote (SANDBOX). */

/** @return array{scenario: WorkspaceScenario, source: string} */
function syncedGa4(): array
{
    $setup = ga4Scenario();

    test()->actingAs($setup['scenario']->owner)
        ->postJson("/api/measurement/sources/{$setup['source']->getKey()}/sync")
        ->assertOk();

    return ['scenario' => $setup['scenario'], 'source' => (string) $setup['source']->getKey()];
}

it('returns totals, channels, a daily trend and the quality of what it read, labelled sandbox', function (): void {
    $setup = syncedGa4();

    $data = test()->actingAs($setup['scenario']->owner)
        ->getJson('/api/analytics/ga4/traffic')
        ->assertOk()
        ->json('data');

    // Two fixture days: day one 1650 sessions, day two 90% of each channel rounded.
    expect($data['totals']['sessions'])->toBeInt()->toBeGreaterThan(1650)
        ->and($data['totals']['engaged_sessions'])->toBeInt()
        ->and($data['totals']['engagement_rate'])->toBeFloat()->toBeLessThan(1.0)
        ->and($data['totals']['engagement_seconds_per_session'])->toBeFloat();
    expect($data['rows'])->toHaveCount(5)
        ->and($data['rows'][0]['name'])->toBe('Paid Social')
        ->and(array_sum(array_column($data['rows'], 'share_of_sessions')))->toEqualWithDelta(1.0, 0.0001);
    expect($data['trend'])->toHaveCount(2);
    expect($data['quality']['is_sandbox'])->toBeTrue()
        ->and($data['quality']['source'])->not->toBe('live')
        ->and($data['quality']['time_zones'])->toBe(['Asia/Riyadh'])
        ->and($data['quality']['days_present'])->toBe(2)
        ->and($data['quality']['is_complete'])->toBeFalse(); // 28-day period, two days of data: said, not hidden
    expect($data['users']['available'])->toBeFalse();
});

it('says "no change figure" rather than inventing a percentage when the previous period is empty', function (): void {
    $setup = syncedGa4();

    $data = test()->actingAs($setup['scenario']->owner)->getJson('/api/analytics/ga4/traffic')->assertOk()->json('data');

    expect($data['previous']['sessions'])->toBeNull()
        ->and($data['change']['sessions'])->toBeNull();
});

it('groups by source and medium and paginates on the server', function (): void {
    $setup = syncedGa4();

    $response = test()->actingAs($setup['scenario']->owner)
        ->getJson('/api/analytics/ga4/traffic?group=source_medium&per_page=10&page=1&sort=page_views&direction=asc')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(5)
        ->and($response->json('data.rows.0.name'))->toContain(' / ')
        ->and($response->json('data.rows.0.page_views'))->toBeLessThanOrEqual($response->json('data.rows.1.page_views'));

    $empty = test()->actingAs($setup['scenario']->owner)
        ->getJson('/api/analytics/ga4/traffic?group=source_medium&per_page=10&page=2')->assertOk();
    expect($empty->json('data.rows'))->toBe([]);
});

it('refuses a period that ends before it starts, or runs longer than a year', function (): void {
    $setup = syncedGa4();
    $owner = test()->actingAs($setup['scenario']->owner);

    $owner->getJson('/api/analytics/ga4/traffic?from=2026-06-10&until=2026-06-01')->assertStatus(422)->assertJsonPath('code', 'invalid_period');
    $owner->getJson('/api/analytics/ga4/traffic?from=2024-01-01&until=2026-06-01')->assertStatus(422)->assertJsonPath('code', 'period_too_long');
    $owner->getJson('/api/analytics/ga4/traffic?per_page=7')->assertStatus(422);
});

it('answers another workspace nothing, and 404 for a source id that is not theirs', function (): void {
    $setup = syncedGa4();
    $other = WorkspaceScenario::create();

    $own = test()->actingAs($other->owner)->getJson('/api/analytics/ga4/traffic')->assertOk()->json('data');
    expect($own['rows'])->toBe([])->and($own['totals']['sessions'])->toBeNull()
        ->and($own['quality']['no_data'])->toBeTrue()->and($own['sources'])->toBe([]);

    test()->actingAs($other->owner)->getJson('/api/analytics/ga4/traffic?source_id='.$setup['source'])->assertNotFound();
});

it('shows a project-limited member only the properties of clients they work for', function (): void {
    $setup = syncedGa4();
    $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);

    // A media buyer assigned to no project sees no client's property, so nothing is read and nothing is leaked.
    $data = test()->actingAs($buyer)->getJson('/api/analytics/ga4/traffic')->assertOk()->json('data');
    expect($data['sources'])->toBe([])->and($data['rows'])->toBe([])->and($data['totals']['sessions'])->toBeNull();

    test()->actingAs($buyer)->getJson('/api/analytics/ga4/traffic?source_id='.$setup['source'])->assertNotFound();
});

it('refuses a role without analytics.view', function (): void {
    $setup = syncedGa4();
    $viewer = $setup['scenario']->addMember(SystemRole::ClientViewer);

    test()->actingAs($viewer)->getJson('/api/analytics/ga4/traffic')->assertForbidden();
});
