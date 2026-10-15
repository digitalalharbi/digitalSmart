<?php

declare(strict_types=1);

namespace App\Application\Integration\DTO;

use App\Domain\Integration\ConnectionBusinessState;
use App\Domain\Integration\ConnectionOwnerType;
use App\Domain\Integration\ConnectionStatus;
use DateTimeImmutable;

/**
 * One connection, as the integrations surface lists it.
 *
 * Never carries credentials, and the technical status message is present only for an actor who may
 * administer integrations — a project manager gets the business state and nothing to misread.
 */
final readonly class ConnectionView
{
    public function __construct(
        public string $id,
        public string $provider,
        public string $providerLabel,
        /** The human label: "Google Ads — Smart Code", never only a provider id. */
        public string $label,
        public ConnectionOwnerType $ownerType,
        public ?string $ownerClientId,
        public ?string $ownerClientName,
        public ConnectionStatus $status,
        public ConnectionBusinessState $state,
        public int $accountsDiscovered,
        public int $accountsBound,
        public int $accountsUnassigned,
        public ?DateTimeImmutable $lastCheckedAt,
        public ?DateTimeImmutable $expiresAt,
        public ?string $technicalMessage,
    ) {}
}
