<?php

declare(strict_types=1);

namespace App\Application\Leads\DTO;

use App\Domain\Commerce\AttributionEvidence;
use App\Domain\Leads\LeadSourceKind;
use App\Domain\Leads\LeadStage;
use DateTimeImmutable;

/**
 * One lead, as a reader sees it.
 *
 * Contact details are present because the people working a pipeline need them, and `LeadResource` decides
 * who may see them. They are **never** included in a client-portal response: they belong to the agency's
 * client's prospect, not to the agency.
 */
final readonly class LeadView
{
    public function __construct(
        public string $id,
        public string $clientId,
        public string $clientName,
        public ?string $projectId,
        public LeadSourceKind $sourceKind,
        public ?string $sourceDetail,
        public LeadStage $stage,
        public ?string $disqualifiedReason,
        public ?string $ownerId,
        public ?string $ownerName,
        public ?string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $message,
        public ?int $valueMinor,
        public ?string $currency,
        public AttributionEvidence $evidence,
        public ?string $utmSource,
        public ?string $utmCampaign,
        public DateTimeImmutable $receivedAt,
        public ?DateTimeImmutable $firstContactedAt,
        public ?DateTimeImmutable $qualifiedAt,
        public ?DateTimeImmutable $closedAt,
    ) {}

    /**
     * How long somebody took to make first contact, in hours, or null.
     *
     * The figure that decides whether a lead was worth buying. A lead contacted within an hour converts at a
     * different rate from one contacted next week, and an agency that does not measure this cannot tell a
     * bad campaign from a slow sales team.
     */
    public function hoursToFirstContact(): ?float
    {
        if ($this->firstContactedAt === null) {
            return null;
        }

        return round(
            ($this->firstContactedAt->getTimestamp() - $this->receivedAt->getTimestamp()) / 3600,
            1,
        );
    }
}
