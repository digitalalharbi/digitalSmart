<?php

declare(strict_types=1);

namespace App\Application\Integration\Contract;

use App\Application\Integration\DTO\DiscoveredAccount;
use App\Application\Integration\DTO\ProviderHealth;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderCapability;

/**
 * The stable port every ad platform sits behind.
 *
 * No provider-specific type crosses this boundary: adapters normalise their own
 * payloads, errors and rate-limit behaviour into these shapes, so neither the
 * domain nor a controller ever learns which platform it is talking to.
 *
 * An adapter must not claim a capability its provider lacks, and must not
 * simulate one it has not implemented. Where a live handshake is impossible
 * because credentials have not been issued, the adapter throws
 * ProviderNotConfigured rather than returning a plausible-looking success.
 */
interface ProviderAdapter
{
    public function provider(): Provider;

    /**
     * What this adapter can actually do today.
     *
     * This is the adapter's own answer, which may be narrower than the
     * platform's published capabilities while an implementation is pending. The
     * interface reads this, not Provider::capabilities().
     *
     * @return list<ProviderCapability>
     */
    public function capabilities(): array;

    public function supports(ProviderCapability $capability): bool;

    /**
     * Where to send someone to authorise this provider.
     *
     * @throws \App\Application\Integration\Exception\ProviderNotConfigured when credentials are absent
     * @throws \App\Application\Integration\Exception\ProviderUnavailable when the provider has no published flow
     */
    public function authorisationUrl(Credentials $credentials, string $redirectUri, string $state): string;

    /**
     * Ad accounts reachable with these credentials.
     *
     * @return list<DiscoveredAccount>
     *
     * @throws \App\Application\Integration\Exception\ProviderNotConfigured
     * @throws \App\Application\Integration\Exception\ProviderRequestFailed
     */
    public function discoverAccounts(Credentials $credentials): array;

    /**
     * Whether the provider is reachable and the credentials still work.
     *
     * Must not throw: a failed check is a reported state, not an exception, so
     * one unhealthy provider never breaks a page listing all of them.
     */
    public function checkHealth(Credentials $credentials): ProviderHealth;
}
