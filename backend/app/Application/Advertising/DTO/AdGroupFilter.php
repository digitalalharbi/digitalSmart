<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

use App\Domain\Advertising\EntityStatus;

/**
 * What the Ad Groups screen narrows to *beyond* the campaign-level filter it shares with the Campaigns screen.
 *
 * Client, project, platform, account, objective and campaign are the campaigns' own and travel in a
 * {@see CampaignControlFilter}; these are the ad group's: its own status, its own name, which ad groups.
 */
final readonly class AdGroupFilter
{
    /**
     * @param  list<EntityStatus>  $statuses  The ad group's own status, not its campaign's.
     * @param  list<string>  $adGroupIds  Specific ad groups — an ad group's own page asks for exactly one.
     */
    public function __construct(
        public array $statuses = [],
        /** Matches the ad group's, its campaign's, its client's or its project's name. */
        public ?string $search = null,
        public array $adGroupIds = [],
        /** Only ad groups that spent and *reported* zero results; one that reported nothing is not one. */
        public bool $reportedNoResults = false,
        public string $sort = 'spend',
        public bool $descending = false,
    ) {}

    /** @return list<string> */
    public static function sorts(): array
    {
        return ['spend', 'name', 'cost', 'roas', 'ctr'];
    }
}
