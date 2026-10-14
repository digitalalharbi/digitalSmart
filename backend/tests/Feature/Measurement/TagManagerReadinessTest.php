<?php

declare(strict_types=1);

use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Access\SystemRole;
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
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Tag Manager readiness
|--------------------------------------------------------------------------
|
| A container reports configuration, not events, so it is assessed rather than synced. The two
| facts this surface exists to establish are whether anything is actually published, and whether
| the published thing can fire — neither of which is visible anywhere else in the product.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project} */
function gtmScenario(bool $sandbox = true): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    $values = [];

    foreach (Provider::GoogleTagManager->requiredCredentialKeys() as $key) {
        $values[$key] = $sandbox ? Credentials::SANDBOX_TOKEN : 'a-real-looking-secret';
    }

    $connection = ProviderConnection::register(
        id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
        workspaceId: new WorkspaceId((string) $scenario->workspace->getKey()),
        provider: Provider::GoogleTagManager,
        owner: ConnectionOwner::agency(),
        name: 'Google Tag Manager',
        credentials: new Credentials($values),
    );

    app(ProviderConnectionRepository::class)->save($connection);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project];
}

/**
 * Register a container as a measurement source, by its API path.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 */
function registerContainer(
    array $setup,
    string $path = 'accounts/6001144/containers/191002277',
    string $name = 'Noon Retail — Web',
): MeasurementSource {
    /** @var MeasurementSource */
    return MeasurementSource::query()->create([
        'workspace_id' => $setup['scenario']->workspace->getKey(),
        'client_id' => $setup['client']->getKey(),
        'kind' => 'tag_manager',
        'provider' => Provider::GoogleTagManager->value,
        'external_id' => $path,
        'name' => $name,
    ]);
}

/**
 * Register an analytics property whose measurement id the container should refer to.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project}  $setup
 */
function registerProperty(array $setup, string $measurementId = 'G-7QK2M4XZPL'): MeasurementSource
{
    /** @var MeasurementSource */
    return MeasurementSource::query()->create([
        'workspace_id' => $setup['scenario']->workspace->getKey(),
        'client_id' => $setup['client']->getKey(),
        'kind' => 'analytics',
        'provider' => Provider::GoogleAnalytics->value,
        'external_id' => 'properties/412006881',
        'name' => 'Noon Retail — Web',
        /*
         * Where discovery records the stream's `G-` id. The readiness check reads this rather than
         * anything typed at it, which is what makes the cross-check worth having: it compares what
         * somebody registered as measuring this client against what the container actually refers
         * to, and those two drifting apart is invisible everywhere else.
         */
        'metadata' => ['measurement_ids' => [$measurementId]],
    ]);
}

describe('listing containers', function (): void {
    it('lists what the connection can read, with the id a person recognises', function (): void {
        $setup = gtmScenario();

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/measurement/containers')
            ->assertOk()
            ->json('data');

        $container = jsonRow($data, 'container_id', '191002277');

        // `GTM-XXXXXX` is what they will find in their client's page source. A numeric id proves
        // nothing to anybody.
        expect($container['public_id'])->toBe('GTM-NR4QX8V')
            ->and($container['domain_names'])->toContain('noonretail.sa')
            ->and($container['is_web'])->toBeTrue();
    });

    it('distinguishes a server-side container from a web one', function (): void {
        $setup = gtmScenario();

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/measurement/containers')
            ->assertOk()
            ->json('data');

        // So nobody expects web page events from something measuring elsewhere.
        expect(jsonRow($data, 'container_id', '191002290')['is_web'])->toBeFalse()
            ->and(jsonRow($data, 'container_id', '191002290')['usage_contexts'])->toBe(['server']);
    });

    it('refuses a role that cannot manage tracking', function (): void {
        $setup = gtmScenario();

        // This spends the workspace's credentials on somebody else's API.
        $this->actingAs($setup['scenario']->addMember(SystemRole::Creative))
            ->getJson('/api/measurement/containers')
            ->assertForbidden();
    });

    it('answers with nothing when Tag Manager is not connected', function (): void {
        $scenario = WorkspaceScenario::create();

        expect($this->actingAs($scenario->owner)->getJson('/api/measurement/containers')->assertOk()->json('data'))
            ->toBe([]);
    });
});

describe('assessing a published container', function (): void {
    it('reports what is wrong with what is actually deployed', function (): void {
        $setup = gtmScenario();
        $source = registerContainer($setup);

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson("/api/measurement/sources/{$source->getKey()}/readiness")
            ->assertOk()
            ->json('data');

        $codes = array_column($data['issues'], 'code');

        /*
         * The fixture container is a realistic mess: a lead tag with no trigger, a paused debug
         * tag, a Google Ads tag pointing at a trigger that is not in the published version, and an
         * unpublished edit sitting in the workspace.
         */
        expect($data['has_live_version'])->toBeTrue()
            ->and($data['live_version_id'])->toBe('18')
            ->and($codes)->toContain('tag_without_trigger')
            ->and($codes)->toContain('tag_paused')
            ->and($codes)->toContain('tag_trigger_missing')
            ->and($codes)->toContain('container_unpublished_changes')
            ->and($data['is_ready'])->toBeFalse();
    });

    it('does not accuse the tags that fire on built-in triggers', function (): void {
        $setup = gtmScenario();
        $source = registerContainer($setup);

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson("/api/measurement/sources/{$source->getKey()}/readiness")
            ->assertOk()
            ->json('data');

        $dangling = array_values(array_filter(
            $data['issues'],
            static fn (array $issue): bool => $issue['code'] === 'tag_trigger_missing',
        ));

        /*
         * The Google tag fires on All Pages (`2147479553`), which Tag Manager never returns in the
         * trigger list. Exactly one tag in this container has a genuinely dangling trigger, and a
         * naive comparison would have reported three.
         */
        expect($dangling)->toHaveCount(1)
            ->and($dangling[0]['subject'])->toBe('18')
            ->and($dangling[0]['evidence']['missing_trigger_ids'])->toBe(['9001']);
    });

    it('finds the measurement ids the published version refers to', function (): void {
        $setup = gtmScenario();
        $source = registerContainer($setup);

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson("/api/measurement/sources/{$source->getKey()}/readiness")
            ->assertOk()
            ->json('data');

        /*
         * Scanned out of the tag parameter tree by the id's own shape, under any key at any depth,
         * because which key holds a measurement id depends on the tag template. The container's own
         * `tagIds` count too — a Google tag configured at container level is still that property
         * being loaded.
         */
        expect($data['measurement_ids_found'])->toContain('G-7QK2M4XZPL')
            ->and($data['measurement_ids_found'])->toContain('AW-882440115')
            ->and($data['measurement_ids_found'])->toContain('GT-PK92MNQ');
    });

    it('says nothing about the measurement id when the container refers to it', function (): void {
        $setup = gtmScenario();
        registerProperty($setup);
        $source = registerContainer($setup);

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson("/api/measurement/sources/{$source->getKey()}/readiness")
            ->assertOk()
            ->json('data');

        expect($data['measurement_ids_expected'])->toBe(['G-7QK2M4XZPL'])
            ->and(array_column($data['issues'], 'code'))->not->toContain('measurement_id_not_in_container');
    });

    it('asks about a property the container never mentions', function (): void {
        $setup = gtmScenario();
        registerProperty($setup, 'G-DIFFERENTONE');
        $source = registerContainer($setup);

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson("/api/measurement/sources/{$source->getKey()}/readiness")
            ->assertOk()
            ->json('data');

        $issue = jsonRow($data['issues'], 'code', 'measurement_id_not_in_container');

        /*
         * The drift this check exists for: the property registered against this client is not the
         * one the live container loads. Reported as a warning phrased as a question, because a
         * custom template or server-side container would not show here.
         */
        expect($issue['severity'])->toBe('warning')
            ->and($issue['subject'])->toBe('G-DIFFERENTONE')
            ->and($issue['evidence']['measurement_ids_found'])->toContain('G-7QK2M4XZPL');
    });

    it('reports a container nobody has published, and nothing else', function (): void {
        $setup = gtmScenario();
        registerProperty($setup);
        $source = registerContainer(
            $setup,
            path: 'accounts/6001144/containers/191002311',
            name: 'Al Waha Clinics — Web',
        );

        $data = $this->actingAs($setup['scenario']->owner)
            ->getJson("/api/measurement/sources/{$source->getKey()}/readiness")
            ->assertOk()
            ->json('data');

        /*
         * The commonest tag-manager failure and the hardest to see: the workspace looks complete,
         * every tag is configured, and the site has no container version at all. One finding,
         * because anything else would bury it.
         */
        expect($data['has_live_version'])->toBeFalse()
            ->and($data['published_tag_count'])->toBe(0)
            ->and(array_column($data['issues'], 'code'))->toBe(['container_never_published'])
            ->and($data['is_ready'])->toBeFalse();
    });

    it('refuses to assess an analytics property as a container', function (): void {
        $setup = gtmScenario();
        $property = registerProperty($setup);

        // A property has no container, and saying so plainly beats an empty page.
        $this->actingAs($setup['scenario']->owner)
            ->getJson("/api/measurement/sources/{$property->getKey()}/readiness")
            ->assertNotFound();
    });

    it('refuses a container the connection can no longer see', function (): void {
        $setup = gtmScenario();
        $source = registerContainer($setup, path: 'accounts/6001144/containers/99999999');

        /*
         * 404 rather than an assessment of something else. A source pointing at a container nobody
         * can read any more must fail loudly — silently assessing a different container would be
         * the worst possible outcome here.
         */
        $this->actingAs($setup['scenario']->owner)
            ->getJson("/api/measurement/sources/{$source->getKey()}/readiness")
            ->assertNotFound();
    });

    it('does not assess another workspace\'s source', function (): void {
        $setup = gtmScenario();
        $other = gtmScenario();
        $source = registerContainer($other);

        $this->actingAs($setup['scenario']->owner)
            ->getJson("/api/measurement/sources/{$source->getKey()}/readiness")
            ->assertNotFound();
    });
});

describe('the live transport is blocked', function (): void {
    it('refuses real credentials instead of reporting a container state it cannot see', function (): void {
        $setup = gtmScenario(sandbox: false);
        $source = registerContainer($setup);

        /*
         * No authorised OAuth client exists, so the container's published state is unknowable. The
         * only acceptable answer is to say so: reporting "ready" or "never published" here would
         * both be fabrications, and either would be acted on.
         */
        $this->actingAs($setup['scenario']->owner)
            ->getJson("/api/measurement/sources/{$source->getKey()}/readiness")
            ->assertStatus(503)
            ->assertJsonPath('code', 'provider_not_configured');
    });
});
