<?php

declare(strict_types=1);

namespace App\Application\Analytics\DTO;

use App\Domain\Advertising\AdEntityType;

/**
 * What a query is restricted to.
 *
 * Also decides which level of rows answers it: filtering by ad means reading
 * ad-level rows, because a campaign row cannot be narrowed to one of its ads.
 * That inference lives here rather than in the read model so a caller cannot
 * accidentally ask for a figure the data cannot support.
 */
final readonly class AnalyticsFilters
{
    /**
     * @param  list<string>  $providers
     * @param  list<string>  $externalAccountIds
     * @param  list<string>  $campaignIds
     * @param  list<string>  $adGroupIds
     * @param  list<string>  $adIds
     * @param  list<string>  $projectIds  narrows to the accounts those projects are bound to
     */
    public function __construct(
        public array $providers = [],
        public array $externalAccountIds = [],
        public array $campaignIds = [],
        public array $adGroupIds = [],
        public array $adIds = [],
        public array $projectIds = [],
        public ?string $clientId = null,
    ) {}

    /**
     * The deepest level these filters require.
     *
     * Campaign-level rows cannot answer a question about one ad group, so the
     * level follows the narrowest filter. Without a structural filter the
     * campaign level is the right default: it is the coarsest level that still
     * supports a platform breakdown, and the account level exists only as one row
     * per account per day.
     */
    public function requiredLevel(): AdEntityType
    {
        return match (true) {
            $this->adIds !== [] => AdEntityType::Ad,
            $this->adGroupIds !== [] => AdEntityType::AdGroup,
            default => AdEntityType::Campaign,
        };
    }

    public function isEmpty(): bool
    {
        return $this->providers === []
            && $this->externalAccountIds === []
            && $this->campaignIds === []
            && $this->adGroupIds === []
            && $this->adIds === []
            && $this->projectIds === []
            && $this->clientId === null;
    }
}
