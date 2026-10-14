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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| The commissioning readout
|--------------------------------------------------------------------------
|
| `commission:readout` is the instrument live commissioning is done with: it asks a provider what it can be
| made to do and prints only what came back. Three properties make it worth trusting, and each is pinned here.
|
|  - It never reports a live verdict from this repository's own fixtures.
|  - It writes nothing, so a run against a client's live account cannot leave a figure behind.
|  - It reports a refusal in the provider's own words rather than as a stack trace.
|
*/

function connect(WorkspaceScenario $scenario, Provider $provider, bool $sandbox = true): void
{
    $credentials = [];

    foreach ($provider->requiredCredentialKeys() as $key) {
        $credentials[$key] = $sandbox ? Credentials::SANDBOX_TOKEN : 'a-real-looking-'.$key;
    }

    app(ProviderConnectionRepository::class)->save(ProviderConnection::register(
        id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
        workspaceId: new WorkspaceId((string) $scenario->workspace->getKey()),
        provider: $provider,
        owner: ConnectionOwner::agency(),
        name: $provider->label(),
        credentials: new Credentials($credentials),
    ));
}

/**
 * Run it and hand back everything it printed.
 *
 * `Artisan::call` rather than the `artisan()` expectation chain, because the console components write a
 * whole block at a time: the chain's first `expectsOutputToContain` consumes that block and every later
 * expectation then matches against nothing. Reading the output and asserting on it says what is meant.
 */
function readout(string $provider): string
{
    expect(Artisan::call('commission:readout', ['provider' => $provider]))->toBe(0);

    return Artisan::output();
}

it('says what a provider needs when nothing is connected', function (): void {
    workspaceScenario();

    /* The key names, never a value — and the document that says who issues them. */
    expect(readout('snapchat'))
        ->toContain('NOT_CONFIGURED')
        ->toContain('client_id, client_secret');
});

/*
 * The rule the whole instrument rests on. The fixtures are in this repository, so a sandbox run proves the
 * mapping and nothing whatever about an account — and an operator reading "verified" against a client's name
 * would have been told the most expensive lie this product could tell.
 */
it('never reports a live verdict from this repository’s own fixtures', function (): void {
    $scenario = workspaceScenario();
    connect($scenario, Provider::GoogleAds);

    $output = readout('google_ads');

    expect($output)
        ->toContain('SANDBOX_VERIFIED')
        ->toContain('this proves the mapping, and nothing about any real account');
    expect($output)->not->toContain('LIVE_READ_VERIFIED');
});

it('reports what the account exposes, including the metrics it does not', function (): void {
    $scenario = workspaceScenario();
    connect($scenario, Provider::GoogleAds);

    /*
     * Coverage as a count, not a verdict. A search campaign reporting no video views is correct; what matters
     * is that the readout says so — Google Ads has no link-click concept, and `linkClicks 0/21` is the honest
     * way to publish that rather than a zero somebody reads as "nobody clicked".
     */
    expect(readout('google_ads'))
        ->toContain('Accounts discovered')
        ->toContain('Metric coverage')
        ->toContain('linkClicks 0/')
        ->toContain('Currency on the rows')
        ->toContain('Account timezone');
});

/* A probe, not a sync. Nothing it reads may reach a table somebody later reports from. */
it('writes nothing', function (): void {
    $scenario = workspaceScenario();
    connect($scenario, Provider::GoogleAds);

    $before = [
        'campaigns' => DB::table('campaigns')->count(),
        'metrics' => DB::table('daily_metrics')->count(),
        'accounts' => DB::table('external_accounts')->count(),
    ];

    readout('google_ads');

    expect(DB::table('campaigns')->count())->toBe($before['campaigns'])
        ->and(DB::table('daily_metrics')->count())->toBe($before['metrics'])
        /* Discovery included: reading which accounts exist is not the same act as binding one. */
        ->and(DB::table('external_accounts')->count())->toBe($before['accounts']);
});

/*
 * Real-looking credentials against a provider nobody has authorised: the adapter refuses rather than serving
 * fixtures, and the readout carries that refusal through in the provider's own words. "Google refused this
 * developer token" is the most useful sentence a commissioning run can produce.
 */
it('reports a refusal as a finding rather than a crash', function (): void {
    $scenario = workspaceScenario();
    connect($scenario, Provider::GoogleAds, sandbox: false);

    $output = readout('google_ads');

    /* Nothing was read, so nothing is verified: the refusal is the finding. */
    expect($output)->toContain('FAILED_EXTERNAL');
    expect($output)->not->toContain('SANDBOX_VERIFIED');
    expect($output)->not->toContain('LIVE_READ_VERIFIED');
});

it('refuses to guess which workspace’s credentials to spend', function (): void {
    $first = workspaceScenario('First');
    $second = workspaceScenario('Second');
    connect($first, Provider::GoogleAds);
    connect($second, Provider::GoogleAds);

    expect(readout('google_ads'))->toContain('Name one with --workspace');
});

it('rejects a provider id that does not exist', function (): void {
    $this->artisan('commission:readout', ['provider' => 'not-a-provider'])->assertExitCode(1);
});
