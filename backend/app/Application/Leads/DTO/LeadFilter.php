<?php

declare(strict_types=1);

namespace App\Application\Leads\DTO;

use App\Domain\Leads\LeadScope;

/** The narrowing a lead list may apply beyond visibility. Every field is optional and they combine with AND. */
final readonly class LeadFilter
{
    public function __construct(
        public LeadScope $scope = LeadScope::All,
        public ?string $actorId = null,
        public ?string $projectId = null,
        public ?string $ownerId = null,
        public ?string $sourceKind = null,
        public ?string $campaign = null,
    ) {}
}
