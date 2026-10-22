<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

/** A page of ad groups, with the figures that describe everything that matched and each parent campaign's verdict. */
final readonly class AdGroupPage
{
    /**
     * @param  list<EntityControlRow>  $rows  The page only.
     * @param  array<string, CampaignControlRow>  $campaigns  Parent campaigns of this page, by id — their health is
     *                                                        the campaign's own, shown beside the ad group and never
     *                                                        passed off as the ad group's.
     * @param  array<string, int>  $spendByCurrency  Over everything that matched, never added across currencies.
     */
    public function __construct(
        public array $rows,
        public array $campaigns,
        public int $matched,
        /** True when the read stopped at the assessment cap, so the counts describe the largest, not all. */
        public bool $isTruncated,
        public array $spendByCurrency,
        public int $days,
        public int $limit,
        public int $offset,
    ) {}
}
