<?php

declare(strict_types=1);

namespace App\Domain\Integration;

/**
 * What an operator needs to know about a connection, in four words.
 *
 * The technical state — an expired token, a revoked grant, a transport error — is kept and shown under
 * *Advanced*. A project manager deciding whether to chase somebody needs "needs reconnect" and does not
 * need to read an OAuth error to get it.
 */
enum ConnectionBusinessState: string
{
    case Healthy = 'healthy';
    case NeedsReconnect = 'needs_reconnect';
    case SyncIssue = 'sync_issue';
    case AwaitingAccess = 'awaiting_access';

    public static function fromStatus(ConnectionStatus $status): self
    {
        return match ($status) {
            ConnectionStatus::Connected => self::Healthy,
            ConnectionStatus::Expired, ConnectionStatus::Revoked => self::NeedsReconnect,
            ConnectionStatus::Error => self::SyncIssue,
            ConnectionStatus::Configured, ConnectionStatus::NotConfigured => self::AwaitingAccess,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::NeedsReconnect => 'Needs reconnect',
            self::SyncIssue => 'Sync issue',
            self::AwaitingAccess => 'Awaiting access',
        };
    }

    public function needsAction(): bool
    {
        return $this !== self::Healthy;
    }
}
