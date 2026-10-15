<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

use App\Domain\Advertising\CampaignHealth;
use DateTimeImmutable;

/** A page of the Campaign Control Center, with the figures that describe the whole set behind it. */
final readonly class CampaignControlPage
{
    /**
     * @param  list<CampaignControlRow>  $rows
     * @param  array<string, int>  $healthCounts  Every state present, so a zero reads as "none".
     * @param  array<string, int>  $spendByCurrency
     * @param  array<string, list<int|null>>  $spendTrend
     */
    public function __construct(
        public array $rows,
        /** Rows matching every filter including health: what pagination pages through. */
        public int $matched,
        /** Campaigns the platform reports as spending, among those assessed. */
        public int $activeCount,
        public array $healthCounts,
        /** Campaigns matching the filter, before the cap and before the page. */
        public int $total,
        /**
         * How many the health verdict was computed over.
         *
         * Published because health is the one filter that cannot be pushed into SQL without a second copy
         * of the rules, so it is applied to a capped read. When this is below `total`, the counts describe
         * the highest-spending slice rather than the whole agency — and saying so is the difference
         * between a caveat and a wrong number.
         */
        public int $assessed,
        public bool $isTruncated,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public int $limit,
        public int $offset,
        /** Spend over the window across every matched campaign, per currency — never summed across them. */
        public array $spendByCurrency = [],
        /** One value per day of the window, per currency. */
        public array $spendTrend = [],
    ) {}

    /** @return list<string> */
    public static function healthStates(): array
    {
        return CampaignHealth::values();
    }
}
