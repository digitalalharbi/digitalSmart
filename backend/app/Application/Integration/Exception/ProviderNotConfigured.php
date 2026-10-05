<?php

declare(strict_types=1);

namespace App\Application\Integration\Exception;

use App\Domain\Integration\Provider;
use RuntimeException;

/**
 * The provider cannot be reached because its credentials have not been issued.
 *
 * This is the `BLOCKED_EXTERNAL` boundary made executable: rather than
 * pretending a connection succeeded, the adapter refuses and says exactly which
 * credential is missing and who can obtain it.
 */
final class ProviderNotConfigured extends RuntimeException
{
    /** @param list<string> $missingKeys */
    public function __construct(
        public readonly Provider $provider,
        public readonly array $missingKeys,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** @param list<string> $missingKeys */
    public static function for(Provider $provider, array $missingKeys): self
    {
        return new self($provider, $missingKeys, sprintf(
            '%s is not configured. Missing credentials: %s. These are issued by the provider '
            .'and cannot be generated here — see docs/INTEGRATIONS.md.',
            $provider->label(),
            implode(', ', $missingKeys),
        ));
    }

    /**
     * Credentials are present, but no live call can be made with them.
     *
     * A different situation from missing credentials, and it reads differently:
     * nothing is wrong with what was entered, and re-entering it will not help.
     * What is missing is the provider's approval of *us*.
     */
    public static function awaitingProviderApproval(Provider $provider): self
    {
        return new self($provider, [], sprintf(
            '%s credentials are stored, but digitalSmart has not been granted API access by the '
            .'provider yet, so no live call can be made. This is an external approval, not a '
            .'configuration mistake — see docs/INTEGRATIONS.md. To exercise the workflow in the '
            .'meantime, configure the connection with sandbox credentials.',
            $provider->label(),
        ));
    }
}
