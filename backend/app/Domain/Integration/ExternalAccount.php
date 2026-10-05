<?php

declare(strict_types=1);

namespace App\Domain\Integration;

use App\Domain\Workspace\WorkspaceId;

/**
 * An ad account discovered under a provider connection.
 *
 * Discovering an account makes it *available* to bind. It does not make it
 * visible to any project: that requires an explicit ProjectIntegrationBinding.
 */
final class ExternalAccount
{
    private function __construct(
        public readonly ExternalAccountId $id,
        public readonly WorkspaceId $workspaceId,
        public readonly ProviderConnectionId $connectionId,
        public readonly Provider $provider,
        /** The provider's own identifier for this account. */
        public readonly string $externalId,
        private string $name,
        private ?string $currency,
        private ?string $timezone,
        private ExternalAccountStatus $status,
    ) {}

    public static function discovered(
        ExternalAccountId $id,
        WorkspaceId $workspaceId,
        ProviderConnectionId $connectionId,
        Provider $provider,
        string $externalId,
        string $name,
        ?string $currency = null,
        ?string $timezone = null,
        ExternalAccountStatus $status = ExternalAccountStatus::Active,
    ): self {
        return new self(
            $id,
            $workspaceId,
            $connectionId,
            $provider,
            trim($externalId),
            trim($name),
            $currency === null ? null : strtoupper(trim($currency)),
            $timezone,
            $status,
        );
    }

    /** Rehydrate from storage without replaying business rules. */
    public static function reconstitute(
        ExternalAccountId $id,
        WorkspaceId $workspaceId,
        ProviderConnectionId $connectionId,
        Provider $provider,
        string $externalId,
        string $name,
        ?string $currency,
        ?string $timezone,
        ExternalAccountStatus $status,
    ): self {
        return new self($id, $workspaceId, $connectionId, $provider, $externalId, $name, $currency, $timezone, $status);
    }

    /** Apply what a rediscovery saw, without losing the local identity. */
    public function refreshFrom(string $name, ?string $currency, ?string $timezone, ExternalAccountStatus $status): void
    {
        $this->name = trim($name);
        $this->currency = $currency === null ? null : strtoupper(trim($currency));
        $this->timezone = $timezone;
        $this->status = $status;
    }

    public function isBindable(): bool
    {
        return $this->status->isUsable();
    }

    public function belongsToWorkspace(WorkspaceId $workspaceId): bool
    {
        return $this->workspaceId->equals($workspaceId);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function currency(): ?string
    {
        return $this->currency;
    }

    public function timezone(): ?string
    {
        return $this->timezone;
    }

    public function status(): ExternalAccountStatus
    {
        return $this->status;
    }
}
