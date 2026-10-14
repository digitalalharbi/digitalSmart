<?php

declare(strict_types=1);

use App\Domain\Integration\ConnectionOwner;
use App\Domain\Integration\ConnectionStatus;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\ExternalAccountStatus;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderCapability;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Shared\Exception\MissingCredentials;
use App\Domain\Workspace\WorkspaceId;

const WS = '0193f0f4-2b7a-7cc0-9a1b-4a5f6d7e8c91';
const CONN = '0193f0f4-2b7a-7cc0-9a1b-4a5f6d7e8c92';

/** @param array<string, string> $values */
function connectionWith(Provider $provider, array $values = []): ProviderConnection
{
    return ProviderConnection::register(
        new ProviderConnectionId(CONN),
        new WorkspaceId(WS),
        $provider,
        ConnectionOwner::agency(),
        '',
        new Credentials($values),
    );
}

describe('Credentials', function (): void {
    it('drops blank values rather than storing empty secrets', function (): void {
        $credentials = new Credentials(['client_id' => 'abc', 'client_secret' => '   ']);

        expect($credentials->describe())->toBe(['client_id'])
            ->and($credentials->get('client_secret'))->toBeNull();
    });

    it('reports exactly which keys are missing', function (): void {
        $credentials = new Credentials(['client_id' => 'abc']);

        expect($credentials->missing(['client_id', 'client_secret', 'developer_token']))
            ->toBe(['client_secret', 'developer_token']);
    });

    it('is never complete against an empty requirement list', function (): void {
        // A provider with no declared credentials is unknown, not ready.
        expect((new Credentials(['anything' => 'x']))->isComplete([]))->toBeFalse();
    });

    it('throws naming the missing keys', function (): void {
        expect(fn () => (new Credentials)->assertComplete(Provider::Meta, ['client_id']))
            ->toThrow(MissingCredentials::class, 'client_id');
    });

    it('never exposes values when dumped', function (): void {
        $credentials = new Credentials(['client_secret' => 'super-secret-value']);
        $dumped = print_r($credentials->__debugInfo(), true);

        expect($dumped)->not->toContain('super-secret-value')
            ->and($dumped)->toContain('[redacted]');
    });
});

describe('Provider', function (): void {
    it('never claims capabilities for a platform we have no contract for', function (): void {
        // ChatGPT Ads is not generally available; guessing here would put
        // controls in the UI that cannot work.
        expect(Provider::OpenAI->capabilities())->toBe([])
            ->and(Provider::OpenAI->supports(ProviderCapability::MetricsSync))->toBeFalse();
    });

    it('declares required credentials for every provider', function (): void {
        foreach (Provider::cases() as $provider) {
            expect($provider->requiredCredentialKeys())->not->toBeEmpty()
                ->and($provider->label())->not->toBe('');
        }
    });

    it('marks mutations as write capabilities', function (): void {
        expect(ProviderCapability::BudgetMutation->isWrite())->toBeTrue()
            ->and(ProviderCapability::CampaignStatusMutation->isWrite())->toBeTrue()
            ->and(ProviderCapability::MetricsSync->isWrite())->toBeFalse();
    });
});

describe('ProviderConnection', function (): void {
    it('is not configured until every required credential is present', function (): void {
        $connection = connectionWith(Provider::GoogleAds, ['client_id' => 'a', 'client_secret' => 'b']);

        // Google also needs a developer token.
        expect($connection->status())->toBe(ConnectionStatus::NotConfigured)
            ->and($connection->isReadyToAuthorise())->toBeFalse();
    });

    it('is configured, but still not connected, once credentials are complete', function (): void {
        $connection = connectionWith(Provider::Meta, ['client_id' => 'a', 'client_secret' => 'b']);

        // Having secrets is not the same as having authorised.
        expect($connection->status())->toBe(ConnectionStatus::Configured)
            ->and($connection->canSync())->toBeFalse();
    });

    it('only syncs once connected', function (): void {
        $connection = connectionWith(Provider::Meta, ['client_id' => 'a', 'client_secret' => 'b']);
        $connection->markConnected('owner-1', null, new DateTimeImmutable);

        expect($connection->canSync())->toBeTrue()
            ->and($connection->status())->toBe(ConnectionStatus::Connected);
    });

    it('invalidates the authorisation when credentials are replaced', function (): void {
        $connection = connectionWith(Provider::Meta, ['client_id' => 'a', 'client_secret' => 'b']);
        $connection->markConnected('owner-1', new DateTimeImmutable('2030-01-01'), new DateTimeImmutable);

        $connection->replaceCredentials(new Credentials(['client_id' => 'c', 'client_secret' => 'd']));

        expect($connection->status())->toBe(ConnectionStatus::Configured)
            ->and($connection->canSync())->toBeFalse()
            ->and($connection->externalAccountOwnerId())->toBeNull()
            ->and($connection->expiresAt())->toBeNull();
    });

    it('knows when its token has expired', function (): void {
        $connection = connectionWith(Provider::Meta, ['client_id' => 'a', 'client_secret' => 'b']);
        $connection->markConnected(null, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable);

        expect($connection->hasExpired(new DateTimeImmutable('2026-06-01')))->toBeTrue()
            ->and($connection->hasExpired(new DateTimeImmutable('2025-06-01')))->toBeFalse();
    });

    it('records a failure with its reason', function (): void {
        $connection = connectionWith(Provider::Meta, ['client_id' => 'a', 'client_secret' => 'b']);
        $connection->markFailed(ConnectionStatus::Revoked, 'Token revoked by the client.', new DateTimeImmutable);

        expect($connection->status())->toBe(ConnectionStatus::Revoked)
            ->and($connection->status()->needsAttention())->toBeTrue()
            ->and($connection->statusMessage())->toBe('Token revoked by the client.');
    });

    it('falls back to the provider label when given a blank name', function (): void {
        expect(connectionWith(Provider::TikTok)->name())->toBe('TikTok Ads');
    });
});

describe('ExternalAccountStatus', function (): void {
    it('still allows binding an unsettled account, with the reason visible', function (): void {
        // It can still be read; hiding it would be more confusing than labelling it.
        expect(ExternalAccountStatus::Unsettled->isUsable())->toBeTrue()
            ->and(ExternalAccountStatus::Closed->isUsable())->toBeFalse()
            ->and(ExternalAccountStatus::Disabled->isUsable())->toBeFalse();
    });
});
