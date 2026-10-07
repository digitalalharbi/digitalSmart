<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

use App\Domain\Advertising\MetricSource;
use App\Domain\Reporting\Comparability;
use App\Domain\Reporting\ComparisonMode;
use App\Domain\Reporting\RenderMode;
use App\Domain\Reporting\ReportAudience;
use App\Domain\Shared\ValueObject\DateRange;
use DateTimeImmutable;

/**
 * A report, rendered and ready to read.
 *
 * Four things on this object exist so a reader is never left to infer them:
 *
 *  - **`renderedAt`**, always. A figure with no timestamp is a figure somebody will quote next month.
 *  - **`mode`** — live or a snapshot. The one distinction this module cannot blur; see `RenderMode`.
 *  - **`comparability`** — whether the change figures may be read at all, and the sentence to show instead when
 *    they may not. Not a boolean: the reason is the useful part.
 *  - **`source`**, the weakest state anywhere in it. A report whose worst section is sandbox is a sandbox report,
 *    whatever its best section says.
 */
final readonly class RenderedReport
{
    /** @param list<RenderedSection> $sections */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public DateRange $period,
        public ?DateRange $comparisonPeriod,
        public ComparisonMode $comparison,
        public Comparability $comparability,
        public ReportAudience $audience,
        public RenderMode $mode,
        public MetricSource $source,
        public array $sections,
        public DateTimeImmutable $renderedAt,
        public ?string $clientName,
        /** Null in a client-safe render. Not blanked afterwards — never read. */
        public ?string $internalNote,
        /** True where the period itself is still running, which every comparison has to account for. */
        public bool $isPeriodIncomplete,
    ) {}

    public function isEmpty(): bool
    {
        foreach ($this->sections as $section) {
            if (! $section->isEmpty) {
                return false;
            }
        }

        return true;
    }

    public function requiresLabel(): bool
    {
        return $this->source->requiresLabel();
    }
}
