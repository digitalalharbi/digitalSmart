<?php

declare(strict_types=1);

namespace App\Application\Leads\DTO;

use App\Domain\Commerce\OrderAttribution;
use App\Domain\Leads\LeadSourceKind;
use DateTimeImmutable;

/**
 * A lead as it arrives.
 *
 * `sourceKind` is required and has no default, because the one thing this product must never do is accept a
 * lead without knowing what action created it. Every member of that enum is a completed action by a person.
 */
final readonly class LeadInput
{
    public function __construct(
        public string $clientId,
        public LeadSourceKind $sourceKind,
        public DateTimeImmutable $receivedAt,
        /** Whatever the lead itself carried. Never inferred from the hour it arrived. */
        public OrderAttribution $attribution,
        public ?string $projectId = null,
        public ?string $sourceDetail = null,
        public ?string $externalId = null,
        public ?string $name = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $message = null,
        public ?string $landingPage = null,
        public ?int $valueMinor = null,
        public ?string $currency = null,
        public ?string $storeOrderId = null,
        public ?string $measurementSourceId = null,
    ) {}
}
