<?php

declare(strict_types=1);

use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Integration\ConnectionOwner;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| The CRM bridge
|--------------------------------------------------------------------------
|
| Two claims are being tested, and the second is the one that makes the first safe.
|
| **A deal's stage is read, never interpreted from its name.** The fixture carries a stage called "Won" whose
| metadata says it is not closed, and the lead behind it must come back open.
|
| **The CRM owns the stage.** A move made here would be undone by the next sync without anybody being told, so it
| is refused with a message naming where to do it instead — and the refusal is asserted from the API rather than
| from a unit test of a guard.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client} */
function crmScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

    $credentials = [];

    foreach (Provider::HubSpot->requiredCredentialKeys() as $key) {
        $credentials[$key] = Credentials::SANDBOX_TOKEN;
    }

    app(ProviderConnectionRepository::class)->save(ProviderConnection::register(
        id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
        workspaceId: new WorkspaceId((string) $scenario->workspace->getKey()),
        provider: Provider::HubSpot,
        owner: ConnectionOwner::agency(),
        name: 'SANDBOX — HubSpot',
        credentials: new Credentials($credentials),
    ));

    return ['scenario' => $scenario, 'client' => $client];
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client}  $setup
 * @return array<string, mixed>
 */
function syncCrm(array $setup): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($setup['scenario']->owner)
        ->postJson('/api/leads/crm-sync', [
            'client_id' => (string) $setup['client']->getKey(),
            /* Wide enough to take the whole fixture, so the test is about interpretation and not about dates. */
            'since' => '2020-01-01T00:00:00+00:00',
        ])
        ->assertOk()
        ->json('data');
}

describe('reading deals', function (): void {
    /*
     * A count is as capable of being quoted to a client as a chart is, and these figures come from fixtures. The
     * read says so in its own answer rather than leaving the surface to guess from the connection — every other
     * sync in this product returns its source state, and this one did not until the Lead Center gained a control
     * for it.
     */
    it('says what kind of figures it read', function (): void {
        $setup = crmScenario();
        $result = syncCrm($setup);

        expect($result['source'])->toBe('sandbox')
            ->and($result['source_label'])->toBe('Sandbox');
    });

    it('records a won deal as won, with its amount', function (): void {
        $setup = crmScenario();
        $result = syncCrm($setup);

        expect($result['read'])->toBeGreaterThan(0);

        $won = DB::table('leads')->where('external_id', '27451900001')->first();

        expect($won)->not->toBeNull();
        expect($won?->stage)->toBe('won');
        expect($won?->source_kind)->toBe('crm');

        /* 48000.00 SAR in minor units, converted once in the mapper. */
        expect((int) ($won->value_minor ?? 0))->toBe(4_800_000);
        expect($won?->currency)->toBe('SAR');
    });

    it('records a lost deal as lost', function (): void {
        $setup = crmScenario();
        syncCrm($setup);

        $lost = DB::table('leads')->where('external_id', '27451900003')->first();

        expect($lost?->stage)->toBe('lost');
    });

    it('leaves a deal in a middle stage alone, and carries the CRM\'s own label', function (): void {
        $setup = crmScenario();
        $result = syncCrm($setup);

        $open = DB::table('leads')->where('external_id', '27451900002')->first();

        /*
         * `new`, which is where a lead starts — not `qualified`, because "Mudawalat" is not a word in this
         * product's vocabulary and mapping it onto one would be a guess.
         */
        expect($open?->stage)->toBe('new');

        /* The CRM's own label travels with it, so a reader can see where it stands in the system that owns it. */
        expect($open?->source_detail)->toContain('Mudawalat');
        expect($open?->source_detail)->toContain('HubSpot');

        // And the count is published rather than hidden: a bridge reporting only what it understood looks broken.
        expect($result['left_alone'])->toBeGreaterThan(0);
    });

    it('refuses to read a win out of a stage merely called "Won"', function (): void {
        $setup = crmScenario();
        syncCrm($setup);

        $mislabelled = DB::table('leads')->where('external_id', '27451900004')->first();

        /*
         * The case the whole bridge exists for. The fixture's stage `1284920` is labelled "Won" and its metadata
         * says `isClosed: false, probability: 0.9` — a pipeline somebody built by hand. A product that trusted
         * the word would report a sale that has not happened, and put its amount in a revenue figure.
         */
        expect($mislabelled?->stage)->toBe('new');
        expect($mislabelled?->source_detail)->toContain('Won');
        expect($mislabelled?->value_minor)->toBeNull();
    });

    it('records no amount for an open deal, whatever the CRM quoted', function (): void {
        $setup = crmScenario();
        syncCrm($setup);

        $open = DB::table('leads')->where('external_id', '27451900005')->first();

        /*
         * An open deal's amount is a quote — a hope, often a round number somebody typed — and a pipeline total
         * built from hopes is the figure that makes a forecast indefensible.
         */
        expect($open?->value_minor)->toBeNull();
        expect($open?->currency)->toBeNull();
    });

    it('attributes nothing to a campaign', function (): void {
        $setup = crmScenario();
        syncCrm($setup);

        $lead = DB::table('leads')->where('external_id', '27451900001')->first();

        /*
         * A CRM holds no click identifier and no UTM parameters. Reading a campaign out of a deal's name would be
         * the false assignment this product refuses everywhere else.
         */
        expect($lead?->attribution_evidence)->toBe('none');
        expect($lead?->utm_campaign)->toBeNull();
        expect($lead?->click_identifier)->toBeNull();
    });

    it('is idempotent, and a re-read moves a stage the CRM moved', function (): void {
        $setup = crmScenario();
        syncCrm($setup);

        $before = DB::table('leads')->where('source_kind', 'crm')->count();

        /* The CRM moved it: the same deal, now closed-won. */
        DB::table('leads')->where('external_id', '27451900002')->update(['stage' => 'new']);

        syncCrm($setup);

        expect(DB::table('leads')->where('source_kind', 'crm')->count())->toBe($before);

        /*
         * And the stage follows the CRM on a re-read, which is the opposite of the rule for every other source:
         * there a human owns the stage and a later arrival must not touch it. A CRM-sourced lead left at an old
         * stage would show a deal as open after it was won.
         */
        $won = DB::table('leads')->where('external_id', '27451900001')->first();
        expect($won?->stage)->toBe('won');
    });
});

describe('who owns a stage', function (): void {
    it('refuses a move on a CRM-sourced lead, and says where to do it', function (): void {
        $setup = crmScenario();
        syncCrm($setup);

        $lead = DB::table('leads')->where('external_id', '27451900002')->first();

        $response = test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/leads/{$lead?->id}/stage", ['stage' => 'qualified'])
            ->assertStatus(422);

        /*
         * Two systems both claiming to own a stage is how a sales team loses a day's work to a sync: whichever
         * wrote last wins, nobody can tell which that was, and the figure a client sees depends on the order of
         * two jobs. The message names where to do it instead, because somebody working a pipeline needs an
         * instruction rather than a rule.
         */
        expect($response->json('code'))->toBe('invalid_lead_transition');
        expect($response->json('message'))->toContain('HubSpot');
        expect($response->json('message'))->toContain('next sync');
    });

    it('still lets a lead from anywhere else be moved', function (): void {
        $setup = crmScenario();

        $lead = test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads', [
                'client_id' => (string) $setup['client']->getKey(),
                'source_kind' => 'form_submission',
                'name' => 'Reem',
                'phone' => '+966500000009',
            ])
            ->assertCreated()
            ->json('data');

        // The refusal is about ownership, not about leads. Nothing else changed.
        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/leads/{$lead['id']}/stage", ['stage' => 'qualified'])
            ->assertOk();
    });
});

describe('the blocked external boundary', function (): void {
    it('refuses to read a CRM with real credentials, rather than serving fixtures', function (): void {
        $setup = crmScenario();

        $connection = app(ProviderConnectionRepository::class)->agencyConnection(
            new WorkspaceId((string) $setup['scenario']->workspace->getKey()),
            Provider::HubSpot,
        );

        $real = [];

        foreach (Provider::HubSpot->requiredCredentialKeys() as $key) {
            $real[$key] = 'a-real-looking-value';
        }

        expect($connection)->not->toBeNull();

        $connection?->replaceCredentials(new Credentials($real));

        if ($connection instanceof ProviderConnection) {
            app(ProviderConnectionRepository::class)->save($connection);
        }

        /*
         * The honesty this adapter exists to keep. A workspace that had configured a real HubSpot app and was
         * quietly served fixtures would have invented deals in front of a client.
         */
        $response = test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/leads/crm-sync', ['client_id' => (string) $setup['client']->getKey()])
            ->assertStatus(503);

        expect($response->json('code'))->toBe('provider_not_configured');
        expect($response->json('blocked_external'))->toBeTrue();
    });
});
