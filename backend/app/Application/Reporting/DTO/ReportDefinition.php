<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Domain\Reporting\ComparisonMode;
use App\Domain\Reporting\Exception\InvalidReportDefinition;
use App\Domain\Reporting\ReportAudience;
use App\Domain\Reporting\ReportWindow;
use App\Domain\Reporting\SectionKind;
use DateTimeImmutable;

/** A report as somebody defined it. */
final readonly class ReportDefinition
{
    /** @param list<SectionDefinition> $sections */
    public function __construct(
        public string $name,
        public ReportWindow $window,
        public ComparisonMode $comparison,
        public ReportAudience $audience,
        public AnalyticsFilters $filters,
        public array $sections,
        public ?string $description = null,
        public ?string $clientId = null,
        public ?DateTimeImmutable $customFrom = null,
        public ?DateTimeImmutable $customUntil = null,
        /** The agency's own commentary. Never rendered into a client-safe report. */
        public ?string $internalNote = null,
    ) {}

    /** @throws InvalidReportDefinition */
    public function assertValid(): void
    {
        if (trim($this->name) === '') {
            throw InvalidReportDefinition::nameRequired();
        }

        if ($this->sections === []) {
            throw InvalidReportDefinition::sectionsRequired();
        }

        if ($this->window->needsDates() && ($this->customFrom === null || $this->customUntil === null)) {
            throw InvalidReportDefinition::datesRequired();
        }

        foreach ($this->sections as $section) {
            $section->assertValid();

            if ($section->kind === SectionKind::Lead && $this->clientId === null) {
                throw InvalidReportDefinition::leadNeedsClient();
            }

            if ($section->kind === SectionKind::Attribution) {
                if ($this->clientId === null) {
                    throw InvalidReportDefinition::attributionNeedsClient();
                }

                if ($this->audience === ReportAudience::ClientSafe) {
                    throw InvalidReportDefinition::attributionNotClientSafe();
                }
            }

            if ($section->kind !== SectionKind::Store) {
                continue;
            }

            if ($this->clientId === null) {
                throw InvalidReportDefinition::storeNeedsClient();
            }

            if ($this->audience === ReportAudience::ClientSafe && $section->revenueSource?->isModelled() === true) {
                throw InvalidReportDefinition::modelledNotClientSafe();
            }
        }
    }
}
