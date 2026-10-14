<?php

declare(strict_types=1);

namespace App\Domain\Integration;

use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;

/**
 * A workspace's link to one ad platform, under one owner.
 *
 * Owns the credentials and the authorisation state. It does **not** own what a
 * project can see — that is the binding's job — so connecting a platform never
 * by itself exposes an ad account to anyone's project.
 *
 * A workspace holds several of these per platform: the agency's own manager
 * account, and whatever individual clients granted directly. `ConnectionOwner`
 * is what tells them apart, and it decides who re-authorises an expired token
 * and whose connection leaves when a client does.
 */
final class ProviderConnection
{
    private function __construct(
        public readonly ProviderConnectionId $id,
        public readonly WorkspaceId $workspaceId,
        public readonly Provider $provider,
        public readonly ConnectionOwner $owner,
        private string $name,
        private ConnectionStatus $status,
        private Credentials $credentials,
        private ?string $externalAccountOwnerId,
        private ?DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $lastCheckedAt,
        private ?string $statusMessage,
    ) {}

    public static function register(
        ProviderConnectionId $id,
        WorkspaceId $workspaceId,
        Provider $provider,
        ConnectionOwner $owner,
        string $name,
        Credentials $credentials,
    ): self {
        return new self(
            id: $id,
            workspaceId: $workspaceId,
            provider: $provider,
            owner: $owner,
            name: trim($name) !== '' ? trim($name) : $provider->label(),
            // Credentials alone are not a connection. Authorisation still has
            // to happen, and the status says so plainly.
            status: $credentials->isComplete($provider->requiredCredentialKeys())
                ? ConnectionStatus::Configured
                : ConnectionStatus::NotConfigured,
            credentials: $credentials,
            externalAccountOwnerId: null,
            expiresAt: null,
            lastCheckedAt: null,
            statusMessage: null,
        );
    }

    /** Rehydrate from storage without replaying business rules. */
    public static function reconstitute(
        ProviderConnectionId $id,
        WorkspaceId $workspaceId,
        Provider $provider,
        ConnectionOwner $owner,
        string $name,
        ConnectionStatus $status,
        Credentials $credentials,
        ?string $externalAccountOwnerId,
        ?DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $lastCheckedAt,
        ?string $statusMessage,
    ): self {
        return new self(
            $id, $workspaceId, $provider, $owner, $name, $status, $credentials,
            $externalAccountOwnerId, $expiresAt, $lastCheckedAt, $statusMessage,
        );
    }

    public function rename(string $name): void
    {
        $trimmed = trim($name);
        $this->name = $trimmed !== '' ? $trimmed : $this->provider->label();
    }

    public function replaceCredentials(Credentials $credentials): void
    {
        $this->credentials = $credentials;

        // Changing credentials invalidates any existing authorisation.
        $this->status = $credentials->isComplete($this->provider->requiredCredentialKeys())
            ? ConnectionStatus::Configured
            : ConnectionStatus::NotConfigured;
        $this->externalAccountOwnerId = null;
        $this->expiresAt = null;
        $this->statusMessage = null;
    }

    /** Record a completed authorisation handshake. */
    public function markConnected(
        ?string $externalAccountOwnerId,
        ?DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): void {
        $this->status = ConnectionStatus::Connected;
        $this->externalAccountOwnerId = $externalAccountOwnerId;
        $this->expiresAt = $expiresAt;
        $this->lastCheckedAt = $at;
        $this->statusMessage = null;
    }

    public function markFailed(ConnectionStatus $status, string $message, DateTimeImmutable $at): void
    {
        $this->status = $status->needsAttention() ? $status : ConnectionStatus::Error;
        $this->statusMessage = $message;
        $this->lastCheckedAt = $at;
    }

    public function recordHealthy(DateTimeImmutable $at): void
    {
        $this->lastCheckedAt = $at;
        $this->statusMessage = null;
    }

    public function revoke(DateTimeImmutable $at): void
    {
        $this->status = ConnectionStatus::Revoked;
        $this->externalAccountOwnerId = null;
        $this->expiresAt = null;
        $this->lastCheckedAt = $at;
    }

    /** Whether the token is past its expiry at the given moment. */
    public function hasExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    public function canSync(): bool
    {
        return $this->status->canSync();
    }

    /** Credentials are present and complete, so a handshake can be attempted. */
    public function isReadyToAuthorise(): bool
    {
        return $this->credentials->isComplete($this->provider->requiredCredentialKeys());
    }

    public function belongsToWorkspace(WorkspaceId $workspaceId): bool
    {
        return $this->workspaceId->equals($workspaceId);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function status(): ConnectionStatus
    {
        return $this->status;
    }

    public function credentials(): Credentials
    {
        return $this->credentials;
    }

    public function externalAccountOwnerId(): ?string
    {
        return $this->externalAccountOwnerId;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function lastCheckedAt(): ?DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function statusMessage(): ?string
    {
        return $this->statusMessage;
    }
}
