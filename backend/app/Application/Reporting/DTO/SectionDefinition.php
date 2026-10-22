<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Reporting\CreativeView;
use App\Domain\Reporting\Exception\InvalidReportDefinition;
use App\Domain\Reporting\SectionKind;

/** One block of a report, as defined rather than as rendered. */
final readonly class SectionDefinition
{
    /** @param list<Metric> $metrics in the order they are shown */
    public function __construct(
        public SectionKind $kind,
        public array $metrics,
        public ?string $title = null,
        public ?Dimension $dimension = null,
        public ?int $rowLimit = null,
        /** Who claims the revenue, for a store section; null for every other kind. */
        public ?RevenueSource $revenueSource = null,
        /** Which part of the creative picture a creative section shows; null for every other kind. */
        public ?CreativeView $creativeView = null,
    ) {}

    /**
     * Refuse a section nobody could read or trust.
     *
     * Validated here rather than in the controller so the rules hold for the seeder and for any future caller, and
     * stated as four separate refusals rather than one because each needs a different sentence.
     *
     * @throws InvalidReportDefinition
     */
    public function assertValid(): void
    {
        if ($this->kind === SectionKind::Lead || $this->kind === SectionKind::Traffic) {
            return;
        }

        if ($this->kind === SectionKind::Attribution) {
            if ($this->revenueSource === null) {
                throw InvalidReportDefinition::attributionNeedsSource();
            }

            return;
        }

        if ($this->kind === SectionKind::Creative) {
            if ($this->creativeView === null) {
                throw InvalidReportDefinition::creativeViewRequired();
            }

            return;
        }

        if ($this->kind === SectionKind::Store) {
            if ($this->revenueSource === null) {
                throw InvalidReportDefinition::revenueSourceRequired();
            }

            return;
        }

        if ($this->metrics === []) {
            throw InvalidReportDefinition::metricsRequired($this->kind);
        }

        if (count($this->metrics) > $this->kind->metricCap()) {
            throw InvalidReportDefinition::tooManyMetrics($this->kind);
        }

        if ($this->kind->needsDimension() && $this->dimension === null) {
            throw InvalidReportDefinition::dimensionRequired($this->kind);
        }

        foreach ($this->metrics as $metric) {
            if (! $this->kind->accepts($metric)) {
                /*
                 * Reach or frequency in a ranking. The read model already refuses to total them; this refuses the
                 * column, which is where the invitation to add the rows up was.
                 */
                throw InvalidReportDefinition::metricNotAllowedHere($this->kind, $metric);
            }
        }
    }

    /** Rows to return for a ranking or a table. Capped, because a report is read rather than scrolled. */
    public function resolvedLimit(): int
    {
        return max(1, min($this->rowLimit ?? 10, 100));
    }
}
