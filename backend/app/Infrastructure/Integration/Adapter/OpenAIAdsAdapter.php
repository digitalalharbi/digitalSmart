<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration\Adapter;

use App\Application\Integration\DTO\DiscoveredAccount;
use App\Application\Integration\DTO\ProviderHealth;
use App\Application\Integration\Exception\ProviderUnavailable;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderCapability;

/**
 * ChatGPT Ads.
 *
 * BLOCKED_EXTERNAL, and more fundamentally than the others: the programme is
 * not generally available, so there is no published API, no documented
 * capability set and no authorisation flow to implement against. Everything
 * here therefore refuses rather than guesses.
 *
 * This adapter exists so the provider appears in the interface with an honest
 * status, and so the day access is granted there is one file to fill in.
 */
final class OpenAIAdsAdapter extends AbstractProviderAdapter
{
    public function provider(): Provider
    {
        return Provider::OpenAI;
    }

    /**
     * Deliberately empty: we do not know what this platform supports, and a
     * guess here would render controls the UI cannot honour.
     *
     * @return list<ProviderCapability>
     */
    public function capabilities(): array
    {
        return [];
    }

    public function authorisationUrl(Credentials $credentials, string $redirectUri, string $state): string
    {
        throw ProviderUnavailable::noPublishedFlow($this->provider());
    }

    /** @return list<DiscoveredAccount> */
    public function discoverAccounts(Credentials $credentials): array
    {
        throw ProviderUnavailable::noPublishedFlow($this->provider());
    }

    public function checkHealth(Credentials $credentials): ProviderHealth
    {
        return ProviderHealth::notConfigured(
            'ChatGPT Ads is not generally available. There is no published API to connect to yet.',
        );
    }
}
