<?php

declare(strict_types=1);

namespace App\Application\Attribution\DTO;

use App\Domain\Attribution\AttributionCoverage;
use App\Domain\Attribution\AttributionModel;
use App\Domain\Commerce\RevenueSource;
use DateTimeImmutable;

/**
 * One model's answer, with everything needed to judge it.
 *
 * The coverage is not an extra: it is the sentence that stops the rows being read as the whole
 * period. So it is a required constructor argument, built from the same scan as the rows, and it
 * cannot be absent from a response.
 */
final readonly class AttributionView
{
    /**
     * @param  list<AttributedCampaignRow>  $rows
     * @param  list<string>  $caveats
     */
    public function __construct(
        public AttributionModel $model,
        public RevenueSource $revenueSource,
        public AttributionCoverage $coverage,
        public array $rows,
        public DateTimeImmutable $from,
        public DateTimeImmutable $until,
        public int $lookbackDays,
        /** Ad spend in the period that no attributed revenue could be matched to. */
        public ?int $unmatchedSpendMinor = null,
        public ?string $spendCurrency = null,
        /**
         * What this answer cannot say, assembled per request rather than written in documentation.
         *
         * The reader of the page is the one who would otherwise draw the wrong conclusion, so the
         * caveats travel with the figures: which model produced them, how much of the period they
         * cover, and whether spend and revenue on the same row came from the same authority.
         */
        public array $caveats = [],
    ) {}
}
