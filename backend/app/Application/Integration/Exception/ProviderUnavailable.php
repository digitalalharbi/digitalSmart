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

    public static function capabilityUnsupported(Provider $provider, string $capability): self
    {
        return new self($provider, sprintf(
            '%s does not support %s. digitalSmart does not simulate capabilities a provider lacks.',
            $provider->label(),
            $capability,
        ));
    }
}
