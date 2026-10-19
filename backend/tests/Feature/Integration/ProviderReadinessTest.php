<?php

declare(strict_types=1);

use App\Application\Integration\DTO\WriteRequest;
use App\Domain\Advertising\AdEntityType;
use App\Domain\Advertising\MetricSource;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderEntityWrites;
use App\Domain\Integration\WriteOperation;
use App\Infrastructure\Integration\Writer\SandboxCampaignWriter;
use Tests\Support\SyncedWorkspace;

/**
 * The provider readiness matrix, and the evidence behind its one claim above code: every change a platform documents,
 * at every level, runs end to end against sandbox credentials. Nothing in it is live — no real credential exists here.
 */
const ADVERTISING = [Provider::Meta, Provider::GoogleAds, Provider::TikTok, Provider::Snapchat, Provider::X, Provider::LinkedIn];

it('carries out every documented change, at every level, against sandbox credentials — and confirms it as sandbox', function (): void {
    $writer = new SandboxCampaignWriter;
    $ran = 0;

    foreach (ADVERTISING as $provider) {
        $credentials = new Credentials(array_fill_keys($provider->requiredCredentialKeys(), Credentials::SANDBOX_TOKEN));

        foreach (WriteOperation::cases() as $operation) {
            if (! $provider->writeSupport($operation)->allowsAttempt()) {
                continue;
            }

            $outcome = $writer->execute(new WriteRequest(
                provider: $provider,
                operation: $operation,
                credentials: $credentials,
                externalAccountId: 'acct-1',
                externalCampaignId: 'cmp-1',
                dailyBudgetMinor: $operation->isBudget() ? 50_000 : null,
                currency: 'SAR',
                externalEntityId: $operation->level() === AdEntityType::Campaign ? 'cmp-1' : 'entity-1',
            ));

            expect($outcome->isConfirmed)->toBeTrue()
                ->and($outcome->source)->toBe(MetricSource::Sandbox);
            $ran++;
        }
    }

    // Six platforms × eight changes, less the three not documented: Google's ad-group budget, X's ad pause and resume.
    expect($ran)->toBe(6 * count(WriteOperation::cases()) - 3);
});

it('refuses a simulated change against real credentials, at any level', function (): void {
    $outcome = (new SandboxCampaignWriter)->execute(new WriteRequest(
        provider: Provider::Meta,
        operation: WriteOperation::PauseAd,
        credentials: new Credentials(array_fill_keys(Provider::Meta->requiredCredentialKeys(), 'a-real-looking-token')),
        externalAccountId: 'act_1',
        externalCampaignId: '1',
        externalEntityId: '2',
    ));

    expect($outcome->isConfirmed)->toBeFalse();
});

it('publishes provider × level × change with how far each is proven, and nothing live', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    $data = test()->actingAs($w->scenario->owner)->getJson('/api/integrations/readiness')->assertOk()->json('data');
    $by = array_column($data, null, 'provider');

    expect(array_keys($by))->toEqualCanonicalizing(array_map(static fn (Provider $p): string => $p->value, ADVERTISING));

    $pause = $by['meta']['levels']['ad_group']['changes']['pause'];
    expect($pause['support'])->toBe('documented')
        ->and($pause['state'])->toBe('sandbox_verified')
        ->and($pause['reached'])->toBe(['code_verified', 'sandbox_verified'])
        ->and($pause['next'])->toBe('awaiting_credentials')
        ->and($pause['reference'])->toContain('adset_id')
        ->and($by['meta']['credentials'])->toBe('sandbox')
        ->and($by['linkedin']['credentials'])->toBe('none');

    // Not inferred across levels, and said in the platform's words where it said it.
    expect($by['x']['levels']['ad']['changes']['pause']['support'])->toBe('not_offered')
        ->and($by['x']['levels']['ad']['changes']['pause']['because'])->toContain('promoted Tweet')
        ->and($by['google_ads']['levels']['ad_group']['changes']['budget']['support'])->toBe('not_offered')
        ->and($by['meta']['levels']['ad']['changes']['budget']['support'])->toBe('not_offered');

    // A bid is documented on every ad group and offered by nothing here.
    foreach (ADVERTISING as $provider) {
        expect($by[$provider->value]['levels']['ad_group']['changes']['bid']['support'])->toBe('not_wired')
            ->and($by[$provider->value]['live_evidence'])->toBeNull()
            ->and($by[$provider->value]['mcp']['write_route'])->toBe('direct_api');
    }

    // No live rung anywhere: that needs a real round trip, and there has been none.
    $json = json_encode($data, JSON_THROW_ON_ERROR);
    expect($json)->not->toContain('live_read_verified')->and($json)->not->toContain('live_write_verified');

    expect(ProviderEntityWrites::read(Provider::OpenAI))->toBeFalse();
});
