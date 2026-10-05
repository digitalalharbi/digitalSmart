<?php

declare(strict_types=1);

namespace App\Domain\Integration;

/**
 * Lifecycle of a workspace's connection to a provider.
 *
 * `Configured` is deliberately distinct from `Connected`: credentials being
 * present is not the same as a completed authorisation, and conflating them is
 * how an interface ends up claiming a connection that does not exist.
 */
enum ConnectionStatus: string
{
    /** No credentials yet. The provider is listed but cannot be used. */
    case NotConfigured = 'not_configured';
    /** Credentials stored; the authorisation handshake has not completed. */
    case Configured = 'configured';
    /** Authorised and usable. */
    case Connected = 'connected';
    /** The access token expired and refresh has not succeeded. */
    case Expired = 'expired';
    /** The provider or the client revoked access. */
    case Revoked = 'revoked';
    /** Last interaction failed; see the recorded message. */
    case Error = 'error';

    public function canSync(): bool
    {
        return $this === self::Connected;
    }

    /** Whether someone needs to act before this connection works again. */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Expired, self::Revoked, self::Error], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::NotConfigured => 'Not configured',
            self::Configured => 'Awaiting authorisation',
            self::Connected => 'Connected',
            self::Expired => 'Expired',
            self::Revoked => 'Revoked',
            self::Error => 'Error',
        };
    }
}
