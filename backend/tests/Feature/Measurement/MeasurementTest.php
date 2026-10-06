<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\MeasurementSource;
use App\Infrastructure\Persistence\Eloquent\Model\ObservedEventCount;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Measurement
|--------------------------------------------------------------------------
|
| Two records and a diagnosis: what measures a client's work, what counts as success there,
| and whether any of it is actually working. The rules worth testing are the refusals — a
| store registered as an analytics property, a conversion nothing maps to, two conversions
| claiming the same event — because each of those produces a number somebody would read and
| act on, and none of them would look wrong on a chart.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project} */
function measurementScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sourcePayload(string $clientId, array $overrides = []): array
{
    return [
        'client_id' => $clientId,
        'kind' => 'analytics',
        'provider' => 'google_analytics',
        'external_id' => 'properties/123456',
        'name' => 'Retail GA4',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function conversionPayload(string $clientId, array $overrides = []): array
{
    return [
        'client_id' => $clientId,
        'name' => 'Qualified lead',
        'normalized_event' => 'generate_lead',
        'is_conversion' => true,
        'is_primary' => true,
        'mappings' => [['raw_event_name' => 'generate_lead']],
        ...$overrides,
    ];
}

/** Seed what a source has been seen to report, as a sync would. */
function observe(
    MeasurementSource $source,
    string $rawName,
    int $count = 12,
    ?int $valueMinor = null,
    string $date = '-2 days',
): ObservedEventCount {
    /** @var ObservedEventCount */
    return ObservedEventCount::query()->create([
        'workspace_id' => $source->getAttribute('workspace_id'),
        'measurement_source_id' => $source->getKey(),
        'raw_event_name' => $rawName,
        'date' => now()->modify($date)->format('Y-m-d'),
        'event_count' => $count,
        'value_minor' => $valueMinor,
        'currency' => $valueMinor === null ? null : 'SAR',
    ]);
}

describe('access', function (): void {
    it('requires authentication', function (): void {
        $this->getJson('/api/measurement/sources')->assertUnauthorized();
    });

    it('refuses a creative, who has no business in the measurement setup', function (): void {
        $setup = measurementScenario();

        $this->actingAs($setup['scenario']->addMember(SystemRole::Creative))
            ->getJson('/api/measurement/sources')
            ->assertForbidden();
    });

    it('refuses a client viewer', function (): void {
        $setup = measurementScenario();

        /*
         * A client sees results, not the wiring. Measurement setup is where a number's meaning
         * is decided, and that is the agency's to answer for.
         */
        $this->actingAs($setup['scenario']->addMember(SystemRole::ClientViewer))
            ->postJson('/api/measurement/sources', sourcePayload((string) $setup['client']->getKey()))
            ->assertForbidden();
    });

    it('hides a source on a project the actor is not on', function (): void {
        $setup = measurementScenario();
        $owner = $setup['scenario']->owner;

        $this->actingAs($owner)
            ->postJson('/api/measurement/sources', sourcePayload(
                (string) $setup['client']->getKey(),
                ['project_id' => (string) $setup['project']->getKey()],
            ))
            ->assertCreated();

        // A buyer with the permission but no project assignment: authorised to use the page,
        // with nothing on it. Server-side, not hidden in the interface.
        $stranger = $setup['scenario']->addMember(SystemRole::MediaBuyer);

        expect($this->actingAs($stranger)->getJson('/api/measurement/sources')->assertOk()->json('data'))
            ->toBe([]);
    });

    it('shows a client-level source to anybody on one of that client\'s projects', function (): void {
        $setup = measurementScenario();

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/sources', sourcePayload((string) $setup['client']->getKey()))
            ->assertCreated();

        $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);
        ProjectMember::query()->create([
            'project_id' => $setup['project']->getKey(),
            'user_id' => $buyer->getKey(),
            'role_id' => $setup['scenario']->role(SystemRole::MediaBuyer)->getKey(),
        ]);

        /*
         * Measurement is agreed per client before a project uses it, so a project-scoped read
         * that hid the client's GA4 property would hide the thing actually doing the measuring.
         */
        expect($this->actingAs($buyer)->getJson('/api/measurement/sources')->assertOk()->json('data'))
            ->toHaveCount(1);
    });

    it('does not reach into another workspace', function (): void {
        $setup = measurementScenario();
        $other = measurementScenario();

        $id = $this->actingAs($other['scenario']->owner)
            ->postJson('/api/measurement/sources', sourcePayload((string) $other['client']->getKey()))
            ->assertCreated()
            ->json('data.id');

        // 404 rather than 403: confirming it exists elsewhere would itself cross the boundary.
        $this->actingAs($setup['scenario']->owner)
            ->deleteJson("/api/measurement/sources/{$id}")
            ->assertNotFound();
    });
});

describe('registering a source', function (): void {
    it('records a property that has never been read as never synced', function (): void {
        $setup = measurementScenario();

        $data = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/sources', sourcePayload((string) $setup['client']->getKey()))
            ->assertCreated()
            ->json('data');

        expect($data['kind'])->toBe('analytics')
            ->and($data['provider'])->toBe('google_analytics')
            // Derived on read, so it is a statement about now rather than about the last write.
            ->and($data['freshness'])->toBe('never_synced')
            ->and($data['needs_attention'])->toBeTrue()
            // An analytics property reports what a browser said a sale was worth. Not an invoice.
            ->and($data['is_revenue_authority'])->toBeFalse();
    });

    it('calls a store a revenue authority', function (): void {
        $setup = measurementScenario();

        $data = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/sources', sourcePayload((string) $setup['client']->getKey(), [
                'kind' => 'store',
                'provider' => 'salla',
                'external_id' => 'store-9001',
                'name' => 'Salla storefront',
            ]))
            ->assertCreated()
            ->json('data');

        expect($data['is_revenue_authority'])->toBeTrue();
    });

    it('refuses a store registered as an analytics property', function (): void {
        $setup = measurementScenario();

        /*
         * The check this rule exists for. Salla reports what was invoiced; GA4 reports what a
         * page said. Filing one as the other puts the two figures in the same column, where
         * they will disagree by design and nobody will be able to say which is wrong.
         */
        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/sources', sourcePayload((string) $setup['client']->getKey(), [
                'kind' => 'analytics',
                'provider' => 'salla',
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_measurement_source');
    });

    it('refuses an analytics source with no provider behind it', function (): void {
        $setup = measurementScenario();

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/sources', sourcePayload((string) $setup['client']->getKey(), [
                'provider' => null,
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_measurement_source');
    });

    it('allows a CRM or a phone log, which have no platform at all', function (): void {
        $setup = measurementScenario();

        /*
         * A lead somebody took by phone is still a lead. Demanding a provider for these kinds
         * would make the honest cases the ones you cannot record.
         */
        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/sources', sourcePayload((string) $setup['client']->getKey(), [
                'kind' => 'crm',
                'provider' => null,
                'external_id' => 'sheet-of-calls',
                'name' => 'Phone enquiries',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.is_revenue_authority', true);
    });

    it('refuses the same property twice', function (): void {
        $setup = measurementScenario();
        $payload = sourcePayload((string) $setup['client']->getKey());

        $this->actingAs($setup['scenario']->owner)->postJson('/api/measurement/sources', $payload)->assertCreated();

        // Two records for one property would double every count read through it.
        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/sources', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_measurement_source');
    });

    it('validates the shape before the rule', function (): void {
        $setup = measurementScenario();

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/sources', [
                'client_id' => (string) $setup['client']->getKey(),
                'kind' => 'telepathy',
                'external_id' => '',
                'name' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['kind', 'external_id', 'name']);
    });

    it('writes down who registered it', function (): void {
        $setup = measurementScenario();

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/sources', sourcePayload((string) $setup['client']->getKey()))
            ->assertCreated();

        $entry = AuditLog::query()->where('action', AuditAction::MeasurementSourceSaved->value)->sole();

        expect($entry->getAttribute('actor_id'))->toBe((string) $setup['scenario']->owner->getKey())
            ->and($entry->getAttribute('changes'))->toMatchArray([
                'kind' => 'analytics',
                'external_id' => 'properties/123456',
            ]);
    });

    it('records the removal with what it used to be', function (): void {
        $setup = measurementScenario();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/sources', sourcePayload((string) $setup['client']->getKey()))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($setup['scenario']->owner)->deleteJson("/api/measurement/sources/{$id}")->assertNoContent();

        expect(AuditLog::query()->where('action', AuditAction::MeasurementSourceDeleted->value)->sole()
            ->getAttribute('changes'))
            ->toMatchArray(['external_id' => 'properties/123456']);
    });
});

describe('defining a conversion', function (): void {
    it('records the names that mean it', function (): void {
        $setup = measurementScenario();

        $data = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/conversions', conversionPayload((string) $setup['client']->getKey(), [
                'mappings' => [
                    ['raw_event_name' => 'generate_lead'],
                    ['raw_event_name' => 'form_submitted'],
                ],
            ]))
            ->assertCreated()
            ->json('data');

        expect($data['mappings'])->toHaveCount(2)
            ->and($data['is_primary'])->toBeTrue()
            ->and($data['is_intent_signal_only'])->toBeFalse()
            /*
             * Null, not zero. Creating a conversion asks no question about a window, and
             * answering "0" would read as "never happened" — which nobody has looked. The
             * health endpoint is where a count becomes a claim.
             */
            ->and($data['observed_count'])->toBeNull();
    });

    it('refuses a conversion nothing maps to', function (): void {
        $setup = measurementScenario();

        /*
         * A conversion permanently reading zero is worse than no conversion at all: it looks
         * like failure rather than absence, and somebody will eventually optimise against it.
         */
        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/conversions', conversionPayload((string) $setup['client']->getKey(), [
                'mappings' => [],
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_event_definition');
    });

    it('refuses a primary that is not a conversion', function (): void {
        $setup = measurementScenario();

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/conversions', conversionPayload((string) $setup['client']->getKey(), [
                'is_conversion' => false,
                'is_primary' => true,
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_event_definition');
    });

    it('refuses the same raw name twice within one definition', function (): void {
        $setup = measurementScenario();

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/conversions', conversionPayload((string) $setup['client']->getKey(), [
                'mappings' => [
                    ['raw_event_name' => 'generate_lead'],
                    ['raw_event_name' => 'generate_lead'],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_event_definition');
    });

    it('refuses a raw name another conversion already claims', function (): void {
        $setup = measurementScenario();
        $clientId = (string) $setup['client']->getKey();

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/conversions', conversionPayload($clientId))
            ->assertCreated();

        // Both would count every one. A dashboard showing both shows the same lead twice.
        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/conversions', conversionPayload($clientId, [
                'name' => 'Enquiry',
                'is_primary' => false,
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_event_definition');
    });

    it('refuses a name the project already uses', function (): void {
        $setup = measurementScenario();
        $clientId = (string) $setup['client']->getKey();

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/conversions', conversionPayload($clientId))
            ->assertCreated();

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/conversions', conversionPayload($clientId, [
                'mappings' => [['raw_event_name' => 'contact']],
                'is_primary' => false,
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_event_definition');
    });

    it('marks an intent signal as one without refusing it', function (): void {
        $setup = measurementScenario();

        $data = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/conversions', conversionPayload((string) $setup['client']->getKey(), [
                'name' => 'WhatsApp taps',
                'normalized_event' => 'whatsapp_click',
                'mappings' => [['raw_event_name' => 'whatsapp_click']],
            ]))
            ->assertCreated()
            ->json('data');

        /*
         * A click is not a conversation. Counting it is a legitimate choice; reporting it as a
         * lead is not, and this flag is what lets a screen keep the two apart.
         */
        expect($data['is_intent_signal_only'])->toBeTrue();
    });

    it('replaces the mapped names on an edit rather than adding to them', function (): void {
        $setup = measurementScenario();
        $clientId = (string) $setup['client']->getKey();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/conversions', conversionPayload($clientId, [
                'mappings' => [
                    ['raw_event_name' => 'generate_lead'],
                    ['raw_event_name' => 'old_form'],
                ],
            ]))
            ->assertCreated()
            ->json('data.id');

        $data = $this->actingAs($setup['scenario']->owner)
            ->putJson("/api/measurement/conversions/{$id}", conversionPayload($clientId, [
                'mappings' => [['raw_event_name' => 'generate_lead']],
            ]))
            ->assertOk()
            ->json('data');

        // A name somebody removed has to stop counting, or it keeps feeding a total nobody
        // thinks it belongs to.
        expect(array_column($data['mappings'], 'raw_event_name'))->toBe(['generate_lead']);
    });

    it('keeps both sides of an edit, because changing a mapping changes history', function (): void {
        $setup = measurementScenario();
        $clientId = (string) $setup['client']->getKey();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/conversions', conversionPayload($clientId))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($setup['scenario']->owner)
            ->putJson("/api/measurement/conversions/{$id}", conversionPayload($clientId, [
                'name' => 'Qualified lead',
                'mappings' => [['raw_event_name' => 'lead_v2']],
            ]))
            ->assertOk();

        /** @var array{before: array{mappings: list<string>}, after: array{mappings: list<string>}} $changes */
        $changes = AuditLog::query()->where('action', AuditAction::ConversionChanged->value)->sole()
            ->getAttribute('changes');

        /*
         * "Leads halved in March" is sometimes a campaign and sometimes a mapping somebody
         * edited. This is the only record that can say which.
         */
        expect($changes['before']['mappings'])->toBe(['generate_lead'])
            ->and($changes['after']['mappings'])->toBe(['lead_v2']);
    });

    it('records a deletion with what it used to count', function (): void {
        $setup = measurementScenario();

        $id = $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/conversions', conversionPayload((string) $setup['client']->getKey()))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($setup['scenario']->owner)
            ->deleteJson("/api/measurement/conversions/{$id}")
            ->assertNoContent();

        expect(AuditLog::query()->where('action', AuditAction::ConversionDeleted->value)->sole()
            ->getAttribute('changes'))
            ->toMatchArray(['was_primary' => true, 'mapped_names' => ['generate_lead']]);
    });

    it('rejects an event name that is not in the vocabulary', function (): void {
        $setup = measurementScenario();

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/conversions', conversionPayload((string) $setup['client']->getKey(), [
                'normalized_event' => 'vibes',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['normalized_event']);
    });
});

describe('tracking health', function (): void {
    it('says a project with nothing connected is not set up', function (): void {
        $setup = measurementScenario();

        $data = $this->actingAs($setup['scenario']->owner)->getJson('/api/measurement/health')->assertOk()->json('data');

        expect($data['is_healthy'])->toBeFalse()
            ->and(array_column($data['issues'], 'code'))->toBe(['no_source'])
            // Published so a count of zero reads as "none in fourteen days" rather than "zero".
            ->and($data['window_days'])->toBe(14);
    });

    it('reports a source that syncs but has gone quiet', function (): void {
        $setup = measurementScenario();

        MeasurementSource::query()->create([
            'workspace_id' => $setup['scenario']->workspace->getKey(),
            'client_id' => $setup['client']->getKey(),
            'kind' => 'analytics',
            'provider' => 'google_analytics',
            'external_id' => 'properties/777',
            'name' => 'Quiet property',
            'last_synced_at' => now()->subHour(),
            'last_event_at' => now()->subDays(5),
        ]);

        $data = $this->actingAs($setup['scenario']->owner)->getJson('/api/measurement/health')->assertOk()->json('data');

        $issue = jsonRow($data['issues'], 'code', 'no_recent_events');

        /*
         * Reachable, syncing, measuring nothing — the failure the whole area exists to catch,
         * because every report built on it is quietly empty rather than visibly broken.
         */
        expect($issue['severity'])->toBe('critical')
            ->and($data['critical_count'])->toBeGreaterThan(0)
            // Every issue carries its evidence, or nobody believes the next one.
            ->and($issue['evidence'])->toHaveKey('last_event_at');
    });

    it('names an event that is arriving and counting towards nothing', function (): void {
        $setup = measurementScenario();

        /** @var MeasurementSource $source */
        $source = MeasurementSource::query()->create([
            'workspace_id' => $setup['scenario']->workspace->getKey(),
            'client_id' => $setup['client']->getKey(),
            'kind' => 'analytics',
            'provider' => 'google_analytics',
            'external_id' => 'properties/888',
            'name' => 'Live property',
            'last_synced_at' => now()->subHour(),
            'last_event_at' => now()->subHours(2),
        ]);

        observe($source, 'generate_lead', 30);
        observe($source, 'purchase_complete', 4);

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/conversions', conversionPayload((string) $setup['client']->getKey()))
            ->assertCreated();

        $data = $this->actingAs($setup['scenario']->owner)->getJson('/api/measurement/health')->assertOk()->json('data');

        $issue = jsonRow($data['issues'], 'code', 'unmapped_event');

        /*
         * `purchase_complete` looks exactly like a purchase and is not treated as one. One
         * property's `contact` is a form and another's is a phone tap; the mapping is a record
         * of a human decision and nothing here substitutes for it.
         */
        expect($issue['subject'])->toBe('purchase_complete')
            // And the mapped one is counted, so the window is genuinely being read.
            ->and(jsonRow($data['definitions'], 'name', 'Qualified lead')['observed_count'])->toBe(30);
    });

    it('notices a purchase arriving with no money on it', function (): void {
        $setup = measurementScenario();

        /** @var MeasurementSource $source */
        $source = MeasurementSource::query()->create([
            'workspace_id' => $setup['scenario']->workspace->getKey(),
            'client_id' => $setup['client']->getKey(),
            'kind' => 'store',
            'provider' => 'salla',
            'external_id' => 'store-1',
            'name' => 'Storefront',
            'last_synced_at' => now()->subHour(),
            'last_event_at' => now()->subHours(3),
        ]);

        observe($source, 'purchase', 9, valueMinor: null);

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/conversions', conversionPayload((string) $setup['client']->getKey(), [
                'name' => 'Sale',
                'normalized_event' => 'purchase',
                'expects_value' => true,
                'mappings' => [['raw_event_name' => 'purchase']],
            ]))
            ->assertCreated();

        $data = $this->actingAs($setup['scenario']->owner)->getJson('/api/measurement/health')->assertOk()->json('data');

        // Revenue cannot be reported from a count, and a column of nulls is easy to miss.
        expect(array_column($data['issues'], 'code'))->toContain('purchase_without_value');
    });

    it('stays quiet when everything is arriving as declared', function (): void {
        $setup = measurementScenario();

        /** @var MeasurementSource $source */
        $source = MeasurementSource::query()->create([
            'workspace_id' => $setup['scenario']->workspace->getKey(),
            'client_id' => $setup['client']->getKey(),
            'kind' => 'analytics',
            'provider' => 'google_analytics',
            'external_id' => 'properties/999',
            'name' => 'Healthy property',
            'last_synced_at' => now()->subHour(),
            'last_event_at' => now()->subHours(2),
        ]);

        observe($source, 'generate_lead', 51);

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/conversions', conversionPayload((string) $setup['client']->getKey()))
            ->assertCreated();

        $data = $this->actingAs($setup['scenario']->owner)->getJson('/api/measurement/health')->assertOk()->json('data');

        // No news is the only acceptable output for working tracking. A page that always has
        // something on it is a page nobody reads.
        expect($data['issues'])->toBe([])
            ->and($data['is_healthy'])->toBeTrue();
    });

    it('scopes the diagnosis to the client asked about', function (): void {
        $setup = measurementScenario();
        $otherClient = Client::factory()->create(['workspace_id' => $setup['scenario']->workspace->getKey()]);
        Project::factory()->forClient($otherClient)->create();

        $this->actingAs($setup['scenario']->owner)
            ->postJson('/api/measurement/sources', sourcePayload((string) $setup['client']->getKey()))
            ->assertCreated();

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/measurement/health?client_id='.$otherClient->getKey())
            ->assertOk()
            ->json('data');

        // The other client genuinely has nothing, and says so rather than borrowing a neighbour's
        // source to look healthy.
        expect(array_column($data['issues'], 'code'))->toBe(['no_source'])
            ->and($data['sources'])->toBe([]);
    });
});
