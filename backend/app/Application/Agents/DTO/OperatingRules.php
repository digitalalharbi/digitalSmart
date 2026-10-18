<?php

declare(strict_types=1);

namespace App\Application\Agents\DTO;

use App\Domain\Agents\PrimaryKpi;
use App\Domain\Agents\RevenueAuthority;

/**
 * The rules an agent must read before it says anything about a project.
 *
 * Resolved field by field — the project's own value if it set one, else its client's, else the agency default —
 * and `sources` records which of the three each came from. Every recommendation names the target it compared
 * against, and this is how it knows whose it was: a client's CPA is not a project's, and silently using one for the
 * other is the comparison this exists to prevent.
 */
final readonly class OperatingRules
{
    /**
     * @param  list<string>  $forbiddenTerms
     * @param  list<array{date: string, label: string}>  $importantDates
     * @param  array<string, 'project'|'client'|'agency_default'>  $sources
     */
    public function __construct(
        public PrimaryKpi $primaryKpi,
        public ?int $targetCpaMinor,
        public ?int $targetCplMinor,
        public ?float $targetRoas,
        public ?int $minSpendMinor,
        public ?int $maxDailyBudgetMinor,
        public int $maxBudgetChangePct,
        public RevenueAuthority $revenueAuthority,
        public array $forbiddenTerms,
        public array $importantDates,
        public array $sources,
    ) {}

    public function sourceOf(string $field): string
    {
        return $this->sources[$field] ?? 'agency_default';
    }
}
