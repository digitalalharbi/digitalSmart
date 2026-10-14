<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

use App\Domain\Advertising\CampaignOwnership;

/** One campaign, with the project it belongs to and how that was decided. */
final readonly class CampaignOwnershipView
{
    /** @param list<array{id: string, name: string}> $candidateProjects */
    public function __construct(
        public string $campaignId,
        public string $campaignName,
        public string $provider,
        public string $externalAccountId,
        public string $externalAccountName,
        public string $status,
        public CampaignOwnership $ownership,
        public ?string $projectId,
        public ?string $projectName,
        public ?string $clientId,
        public ?string $clientName,
        /**
         * The projects this campaign could be assigned to — the bindings of its account, and nothing wider.
         *
         * Published rather than left to the client to work out, because the set is the whole safety
         * property: a campaign may only join a project its account is already bound to, or binding would
         * have been bypassed by an assignment.
         */
        public array $candidateProjects,
    ) {}
}
