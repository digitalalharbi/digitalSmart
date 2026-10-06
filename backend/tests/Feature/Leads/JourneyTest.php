<?php

declare(strict_types=1);

use App\Application\Commerce\Contract\CommerceStore;
use App\Application\Commerce\DTO\CustomerRecord;
use App\Application\Commerce\DTO\OrderRecord;
use App\Domain\Access\SystemRole;
use App\Domain\Advertising\MetricSource;
use App\Domain\Commerce\FulfilmentState;
use App\Domain\Commerce\OrderAttribution;
use App\Domain\Commerce\PaymentState;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\Store;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Customer journeys
|--------------------------------------------------------------------------
|
| What one person did, and — more importantly — what this product is honest about not knowing. The marketing
| touches before a conversion are not available: Google Analytics' reporting API returns aggregates rather than
| per-user event streams, and the documented route to event-level data is a BigQuery export this product does
| not read. So these tests check that a single record is never dressed up as a journey.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project, store: Store} */
function journeyScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    /** @var Store $store */
    $store = Store::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'client_id' => $client->getKey(),
        'provider' => Provider::Salla->value,
        'external_id' => 'journey-store',
        'name' => 'Noon Retail',
        'currency' => 'SAR',
    ]);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project, 'store' => $store];
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project, store: Store}  $setup
 * @param  array<string, mixed>  $overrides
 */
function journeyLead(array $setup, array $overrides = []): string
{
    /** @var string */
    return test()->actingAs($setup['scenario']->owner)
        ->postJson('/api/leads', [
            'client_id' => (string) $setup['client']->getKey(),
            'source_kind' => 'form_submission',
            'source_detail' => 'Contact form',
            'name' => 'Layla Al-Harbi',
            'phone' => '0555001122',
            'received_at' => '2026-09-01 10:00:00',
            ...$overrides,
        ])
        ->assertCreated()
        ->json('data.id');
}

/**
 * Give the store a customer and an order for the same person.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project, store: Store}  $setup
 */
function journeyOrder(
    array $setup,
    string $phone = '+966555001122',
    string $placedAt = '2026-09-10 14:00:00',
    int $total = 45_000,
    ?OrderAttribution $attribution = null,
): void {
    $workspaceId = (string) $setup['scenario']->workspace->getKey();
    $storeId = (string) $setup['store']->getKey();
    $commerce = app(CommerceStore::class);

    $commerce->upsertCustomers($workspaceId, $storeId, [
        new CustomerRecord(externalId: 'cust-1', name: 'Layla Al-Harbi', phone: $phone),
    ]);

    $commerce->upsertOrders($workspaceId, $storeId, [
        new OrderRecord(
            externalId: 'order-'.substr(md5($placedAt), 0, 6),
            paymentState: PaymentState::Paid,
            fulfilmentState: FulfilmentState::Delivered,
            currency: 'SAR',
            totalMinor: $total,
            placedAt: new DateTimeImmutable($placedAt),
            attribution: $attribution ?? OrderAttribution::none(),
            reference: 'NR-9001',
            customerExternalId: 'cust-1',
        ),
    ], RevenueSource::Salla, MetricSource::Synced);
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project, store: Store}  $setup
 * @return array<string, mixed>
 */
function fetchJourney(array $setup, string $leadId): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($setup['scenario']->owner)
        ->getJson("/api/leads/{$leadId}/journey")
        ->assertOk()
        ->json('data');
}

describe('what it refuses to call a journey', function (): void {
    it('says a single record is not a journey yet', function (): void {
        $setup = journeyScenario();
        $lead = journeyLead($setup);

        $data = fetchJourney($setup, $lead);

        /*
         * The guard against a one-step funnel being read as a whole customer history. Drawing a journey from one
         * point tells a reader this customer arrived from nowhere — which is both wrong and the sort of wrong
         * somebody acts on.
         */
        expect($data['touchpoint_count'])->toBe(1)
            ->and($data['is_multi_touch'])->toBeFalse()
            ->and($data['span_in_days'])->toBeNull()
            ->and(implode(' ', $data['limitations']))->toContain('not a journey yet');
    });

    it('states that the marketing before a conversion is missing, not absent', function (): void {
        $setup = journeyScenario();
        $lead = journeyLead($setup);

        $data = fetchJourney($setup, $lead);

        /*
         * The limitation that matters most, and the reason this phase does not pretend to do more. It is in the
         * response rather than only in the documentation, because the person reading the page is the one who
         * would otherwise draw the wrong conclusion.
         */
        $limitations = implode(' ', $data['limitations']);

        expect($limitations)->toContain('clicks, sessions and ad impressions')
            ->and($limitations)->toContain('BigQuery');
    });

    it('does not give the journey an attribution of its own', function (): void {
        $setup = journeyScenario();
        $lead = journeyLead($setup, [
            'utm_source' => 'meta',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'autumn-range',
        ]);
        journeyOrder($setup, attribution: OrderAttribution::from(source: 'google', campaign: 'brand'));

        $data = fetchJourney($setup, $lead);

        /*
         * Two touchpoints carrying two different campaigns. Deciding which earned the credit is a *model* —
         * first-touch, last-touch and linear disagree — so the journey has no campaign of its own. What it
         * offers is the earliest record that carried one, labelled as a fact about that record.
         */
        expect($data)->not->toHaveKey('campaign')
            ->and($data)->not->toHaveKey('attribution')
            ->and($data['earliest_attributed_touchpoint']['campaign'])->toBe('autumn-range')
            ->and($data['earliest_attributed_touchpoint']['kind'])->toBe('lead');
    });
});

describe('linking a person across surfaces', function (): void {
    it('joins an enquiry and an order from the same phone, written differently', function (): void {
        $setup = journeyScenario();
        $lead = journeyLead($setup, ['phone' => '0555001122']);
        journeyOrder($setup, phone: '+966 55 500 1122');

        $data = fetchJourney($setup, $lead);

        /*
         * The same number in national and international form. Without the shared normaliser these would be two
         * people, and a client's funnel would show an enquiry that never bought and an order from nowhere.
         */
        expect($data['touchpoint_count'])->toBe(2)
            ->and($data['is_multi_touch'])->toBeTrue()
            ->and(array_column($data['touchpoints'], 'kind'))->toBe(['lead', 'order']);
    });

    it('orders the touchpoints oldest first', function (): void {
        $setup = journeyScenario();
        $lead = journeyLead($setup, ['received_at' => '2026-09-01 10:00:00']);
        journeyOrder($setup, placedAt: '2026-09-10 14:00:00');

        $data = fetchJourney($setup, $lead);

        expect($data['touchpoints'][0]['kind'])->toBe('lead')
            ->and($data['touchpoints'][1]['kind'])->toBe('order')
            /*
             * Nine days and four hours from enquiry to order — the figure an agency wants from this. Reported to
             * one decimal rather than rounded to whole days, because "nine days" and "nine and a bit" are the
             * same answer to a person and the fraction matters when these are averaged.
             */
            ->and($data['span_in_days'])->toBe(9.2);
    });

    it('reports the platform\'s own customer link as the strongest evidence', function (): void {
        $setup = journeyScenario();
        $lead = journeyLead($setup);
        journeyOrder($setup);

        $data = fetchJourney($setup, $lead);

        /*
         * The shop made the link rather than this product inferring it — the customer signed in, or the platform
         * matched them itself — so it outranks a contact match.
         */
        expect($data['identity_evidence'])->toBe('platform_customer')
            ->and($data['identity_confidence'])->toBe(95)
            ->and($data['is_reliable'])->toBeTrue();
    });

    it('links two enquiries from one person on the phone match', function (): void {
        $setup = journeyScenario();
        $first = journeyLead($setup, ['received_at' => '2026-06-01 10:00:00']);
        journeyLead($setup, ['received_at' => '2026-09-01 10:00:00', 'source_kind' => 'phone_call']);

        $data = fetchJourney($setup, $first);

        /*
         * Two separate leads — months apart, so outside the deduplication window — and one person. That is the
         * correct outcome for both: two enquiries happened, and they came from the same phone.
         */
        expect($data['touchpoint_count'])->toBe(2)
            ->and($data['identity_evidence'])->toBe('exact_phone')
            ->and($data['is_reliable'])->toBeTrue();
    });

    it('does not join two different people', function (): void {
        $setup = journeyScenario();
        $lead = journeyLead($setup, ['phone' => '0555001122']);
        journeyOrder($setup, phone: '+966555999888');

        $data = fetchJourney($setup, $lead);

        /*
         * Different numbers, so no link. Merging two people's histories produces one record nobody can
         * disentangle afterwards; the cost of missing a link is only an unlinked journey.
         */
        expect($data['touchpoint_count'])->toBe(1)
            ->and($data['is_multi_touch'])->toBeFalse();
    });

    it('records nothing for an anonymous enquiry', function (): void {
        $setup = journeyScenario();
        $lead = journeyLead($setup, ['phone' => null, 'email' => null, 'name' => 'Walk-in']);

        $data = fetchJourney($setup, $lead);

        /*
         * Nothing to match on, and nothing invented. An anonymous enquiry is a real enquiry that genuinely
         * cannot be joined to anything.
         */
        expect($data['identity_evidence'])->toBe('none')
            ->and($data['identity_confidence'])->toBe(0)
            ->and($data['is_reliable'])->toBeFalse();
    });

    it('matches on an e-mail where there is no phone', function (): void {
        $setup = journeyScenario();
        $lead = journeyLead($setup, ['phone' => null, 'email' => 'Layla@Example.test']);

        journeyOrder($setup);
        app(CommerceStore::class)->upsertCustomers(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $setup['store']->getKey(),
            [new CustomerRecord(externalId: 'cust-1', name: 'Layla', email: 'layla@example.test')],
        );

        $data = fetchJourney($setup, $lead);

        // Case-insensitive, because an address typed with a capital is the same address.
        expect($data['touchpoint_count'])->toBe(2);
    });
});

describe('what each touchpoint carries', function (): void {
    it('keeps each record\'s own attribution rather than sharing one', function (): void {
        $setup = journeyScenario();
        $lead = journeyLead($setup, ['utm_source' => 'meta', 'utm_medium' => 'cpc', 'utm_campaign' => 'autumn']);
        journeyOrder($setup);

        $data = fetchJourney($setup, $lead);

        /*
         * The enquiry carried a campaign and the order carried nothing. Letting the order inherit it would
         * manufacture a link the order never had — which is exactly how an attributed revenue figure comes to be
         * indefensible.
         */
        expect($data['touchpoints'][0]['campaign'])->toBe('autumn')
            ->and($data['touchpoints'][0]['attribution_evidence'])->toBe('utm_parameters')
            ->and($data['touchpoints'][1]['campaign'])->toBeNull()
            ->and($data['touchpoints'][1]['attribution_evidence'])->toBe('none');
    });

    it('carries each record\'s own state and value', function (): void {
        $setup = journeyScenario();
        $lead = journeyLead($setup);
        journeyOrder($setup, total: 45_000);

        $data = fetchJourney($setup, $lead);

        expect($data['touchpoints'][0]['state'])->toBe('new')
            ->and($data['touchpoints'][1]['state'])->toBe('paid')
            ->and($data['touchpoints'][1]['value_minor'])->toBe(45_000)
            ->and($data['recorded_value_minor'])->toBe(45_000);
    });
});

describe('access', function (): void {
    it('requires authentication', function (): void {
        // Any id will do: the guard runs before the lookup, which is the point.
        $this->getJson('/api/leads/01932f00-0000-7000-8000-000000000000/journey')->assertUnauthorized();
    });

    it('refuses a role that cannot read leads', function (): void {
        $setup = journeyScenario();
        $lead = journeyLead($setup);

        $this->actingAs($setup['scenario']->addMember(SystemRole::Creative))
            ->getJson("/api/leads/{$lead}/journey")
            ->assertForbidden();
    });

    it('does not reach into another workspace', function (): void {
        $setup = journeyScenario();
        $other = journeyScenario();
        $lead = journeyLead($other);

        $this->actingAs($setup['scenario']->owner)
            ->getJson("/api/leads/{$lead}/journey")
            ->assertNotFound();
    });

    it('shows nothing for a client the actor cannot reach', function (): void {
        $setup = journeyScenario();
        $lead = journeyLead($setup);
        journeyOrder($setup);

        $stranger = $setup['scenario']->addMember(SystemRole::MediaBuyer);

        /*
         * The lead itself is out of reach, so the journey is a 404 rather than an empty one — confirming a lead
         * exists elsewhere would itself cross the boundary.
         */
        $this->actingAs($stranger)
            ->getJson("/api/leads/{$lead}/journey")
            ->assertNotFound();
    });
});
