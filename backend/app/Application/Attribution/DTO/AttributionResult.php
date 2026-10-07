<?php

declare(strict_types=1);

namespace App\Application\Attribution\DTO;

use App\Domain\Attribution\AttributionCoverage;

/**
 * What the read model found: the rows, and what they account for.
 *
 * One object because the two are computed from one scan and must not be fetched separately — see
 * `AttributionReadModel`.
 */
final readonly class AttributionResult
{
    /** @param list<AttributedCampaignRow> $rows */
    public function __construct(
        public AttributionCoverage $coverage,
        public array $rows,
        /** Ad spend in the period that no attributed revenue was matched to. */
        public ?int $unmatchedSpendMinor = null,
        public ?string $spendCurrency = null,
    ) {}
}
