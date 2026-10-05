<?php

declare(strict_types=1);

namespace App\Application\Integration\Exception;

use App\Domain\Integration\Provider;
use RuntimeException;

/**
 * The provider has no published integration path for what was asked.
 *
 * Distinct from ProviderNotConfigured: no credential would help, because the
 * capability does not exist to be used.
 */
final class ProviderUnavailable extends RuntimeException
{
    public function __construct(public readonly Provider $provider, string $message)
    {
        parent::__construct($message);
    }

    public static function noPublishedFlow(Provider $provider): self
    {
        return new self($provider, sprintf(
            '%s has no published authorisation flow available to us yet. The programme is not '
            .'generally available, so its contract and capabilities are unknown.',
            $provider->label(),
        ));
    }

    /**
     * No sync adapter is implemented for this provider.
     *
     * Says so plainly rather than returning an adapter that would invent field
     * names. The missing piece is our implementation, not the provider's API.
     */
    public static function noSyncAdapter(Provider $provider): self
    {
        return new self($provider, sprintf(
            'No %s sync adapter is implemented, so campaign and performance data cannot be read '
            .'from it. Nothing is wrong with the connection.',
            $provider->label(),
        ));
    }

    public static function capabilityUnsupported(Provider $provider, string $capability): self
    {
        return new self($provider, sprintf(
            '%s does not support %s. digitalSmart does not simulate capabilities a provider lacks.',
            $provider->label(),
            $capability,
        ));
    }
}
