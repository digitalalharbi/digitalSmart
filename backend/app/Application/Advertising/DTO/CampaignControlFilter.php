<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

use App\Domain\Advertising\CampaignHealth;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\EntityStatus;

/**
 * What the Campaign Control Center is narrowed to.
 *
 * All of it applied server-side. Rule 107 is explicit that filters must not be a React array operation,
 * and at two thousand campaigns the difference is between a page and a download.
 */
final readonly class CampaignControlFilter
{
    /** The share of a daily budget at and above which spending is "high". */
    public const BUDGET_HIGH = 0.9;

    /** The share of a daily budget at and below which spending is "low". */
    public const BUDGET_LOW = 0.5;

    /**
     * @param  list<string>  $clientIds
     * @param  list<string>  $projectIds
     * @param  list<string>  $providers
     * @param  list<string>  $externalAccountIds
     * @param  list<EntityStatus>  $statuses
     * @param  list<CampaignObjective>  $objectives
     * @param  list<CampaignHealth>  $health
     */
    public function __construct(
        public array $clientIds = [],
        public array $projectIds = [],
        public array $providers = [],
        public array $externalAccountIds = [],
        public array $statuses = [],
        public array $objectives = [],
        public array $health = [],
        /** Matches a campaign, client, project or account name. */
        public ?string $search = null,
        /** One of `health`, `spend`, `name`, `cost`, `roas`. Applied after verdicts, because health is not a column. */
        public string $sort = 'health',
        public bool $descending = false,
        /**
         * Internal tag ids. A campaign matches when it, its project or its client carries the tag — tagging a
         * project "Ramadan" should not mean tagging each of its forty campaigns by hand.
         *
         * @var list<string>
         */
        public array $tagIds = [],
        /**
         * Specific campaigns — the campaign's own page asks for exactly one, through the same read, so its verdict
         * is by construction the verdict on the card that linked to it.
         *
         * @var list<string>
         */
        public array $campaignIds = [],
        /** Only campaigns the platform says stop within this many days. A campaign with no end date never matches. */
        public ?int $endsWithinDays = null,
        /**
         * `high` — spending at or above nine tenths of a daily budget; `low` — at or below half of it. Only a daily
         * budget has a line to measure against, so a campaign without one matches neither.
         */
        public ?string $budgetState = null,
        /** Only campaigns that spent and *reported* zero results. A campaign that reported nothing is not a zero. */
        public bool $reportedNoResults = false,
    ) {}

    /** @return list<string> */
    public static function budgetStates(): array
    {
        return ['high', 'low'];
    }

    /** @return list<string> */
    public static function sorts(): array
    {
        return ['health', 'spend', 'name', 'cost', 'roas'];
    }

    /**
     * Whether anything narrows the population *before* health is computed.
     *
     * Health is the one filter that cannot be pushed into SQL without a second copy of the rules, so the
     * read model needs to know whether the cheap filters have already cut the set down.
     */
    public function narrowsInSql(): bool
    {
        return $this->clientIds !== []
            || $this->projectIds !== []
            || $this->providers !== []
            || $this->externalAccountIds !== []
            || $this->statuses !== []
            || $this->objectives !== []
            || $this->tagIds !== []
            || $this->campaignIds !== []
            || ($this->search !== null && $this->search !== '');
    }
}
