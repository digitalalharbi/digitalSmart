<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration\Adapter;

use App\Application\Integration\Contract\ProviderAdapter;
use App\Application\Integration\DTO\DiscoveredAccount;
use App\Application\Integration\DTO\ProviderHealth;
use App\Application\Integration\Exception\ProviderNotConfigured;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderCapability;
use App\Infrastructure\Integration\Fixture\SandboxAccounts;

/**
 * Shared behaviour for every ad platform adapter.
 *
 * The live handshakes are BLOCKED_EXTERNAL: none of these providers has issued
 * us credentials, so no adapter can complete a real OAuth exchange or call a
 * real endpoint yet. Rather than stubbing that out with fake successes, each
 * adapter refuses with ProviderNotConfigured naming the exact missing
 * credential.
 *
 * Sandbox mode is the one exception, and it is explicit: when a connection is
 * configured with sandbox credentials the adapter returns clearly labelled
 * SANDBOX fixtures, so the UI, the binding model and the tests can be exercised
 * end to end without anyone mistaking the data for a client's.
 */
abstract class AbstractProviderAdapter implements ProviderAdapter
{
    /**
     * Credential value that opts a connection into fixtures.
     *
     * Deliberately explicit and ugly: nobody types this by accident, and it
     * cannot be confused with a real secret.
     */
    public const SANDBOX_TOKEN = 'sandbox';

    abstract public function provider(): Provider;

    /** @return list<ProviderCapability> */
    public function capabilities(): array
    {
        return $this->provider()->capabilities();
    }

    public function supports(ProviderCapability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function authorisationUrl(Credentials $credentials, string $redirectUri, string $state): string
    {
        $this->assertConfigured($credentials);

        // Reached only in sandbox mode; a real flow needs credentials we do not
        // have. The URL is local and obviously not a provider's.
        return sprintf(
            'https://sandbox.digitalsmart.invalid/%s/authorise?redirect_uri=%s&state=%s',
            $this->provider()->value,
            urlencode($redirectUri),
            urlencode($state),
        );
    }

    /** @return list<DiscoveredAccount> */
    public function discoverAccounts(Credentials $credentials): array
    {
        $this->assertConfigured($credentials);

        return SandboxAccounts::for($this->provider());
    }

    public function checkHealth(Credentials $credentials): ProviderHealth
    {
        $missing = $credentials->missing($this->provider()->requiredCredentialKeys());

        if ($missing !== []) {
            return ProviderHealth::notConfigured(sprintf(
                'Not configured. Missing: %s.',
                implode(', ', $missing),
            ));
        }

        if (! $this->isSandbox($credentials)) {
            // Credentials are present but we have never been able to verify
            // them, because the provider has not granted us access.
            return ProviderHealth::awaitingAuthorisation(
                'Credentials stored. The live authorisation handshake with '
                .$this->provider()->label().' has not been completed.',
            );
        }

        return ProviderHealth::healthy();
    }

    /** Whether this connection is running against fixtures. */
    protected function isSandbox(Credentials $credentials): bool
    {
        foreach ($this->provider()->requiredCredentialKeys() as $key) {
            if ($credentials->get($key) !== self::SANDBOX_TOKEN) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws ProviderNotConfigured when credentials are missing, or when they
     *                               are real and therefore cannot be used yet
     */
    protected function assertConfigured(Credentials $credentials): void
    {
        $required = $this->provider()->requiredCredentialKeys();
        $missing = $credentials->missing($required);

        if ($missing !== []) {
            throw ProviderNotConfigured::for($this->provider(), $missing);
        }

        if (! $this->isSandbox($credentials)) {
            /*
             * Real credentials are present, but we have no approved app to use
             * them with. Failing loudly here is the point: a silent fake
             * success would put invented numbers in front of a client. The
             * message says plainly that nothing the operator entered is wrong.
             */
            throw ProviderNotConfigured::awaitingProviderApproval($this->provider());
        }
    }
}
