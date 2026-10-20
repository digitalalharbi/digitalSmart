<?php

declare(strict_types=1);

namespace App\Application\Presentation\Service;

use App\Application\Analytics\DTO\BreakdownRow;
use App\Application\Analytics\DTO\SeriesPoint;
use App\Application\Presentation\DTO\Slide;
use App\Application\Presentation\DTO\SlideFigure;
use App\Application\Presentation\DTO\SlideSeries;
use App\Application\Presentation\DTO\SlideTable;
use App\Application\Reporting\DTO\RenderedReport;
use App\Application\Reporting\DTO\RenderedSection;
use App\Application\Reporting\DTO\StoreFigure;
use App\Application\Shared\Service\MetricText;
use App\Domain\Analytics\Metric;
use App\Domain\Presentation\SlideKind;
use App\Domain\Reporting\SectionKind;

/**
 * Turns a rendered report into slides.
 *
 * **A deck is a report, laid out for a room.** That is the whole design: the figures come from `RenderReport`,
 * which is the same aggregation the reporting surface and the client portal read, so a deck cannot disagree with
 * the report it was made from. A presentation generator with its own analytics would be a second set of numbers
 * to reconcile, and the reconciliation always happens in front of a client.
 *
 * Two things are added rather than copied.
 *
 * **A caveat slide**, built from the report's own section caveats and its comparability refusal. On screen those
 * are a sentence above the figures; in a room, read out loud by somebody who may not have built the deck, they
 * have to be a slide — a footnote in six-point type is a footnote the projector crops.
 *
 * **A source statement on every figure.** A slide is photographed and forwarded. The badge that labelled a figure
 * `SANDBOX` on screen has to survive being a JPEG in somebody's phone.
 */
final readonly class DeckFromReport
{
    public function __construct(private MetricText $text) {}

    /**
     * @return list<Slide>
     */
    public function slides(RenderedReport $report): array
    {
        $slides = [];

        foreach ($report->sections as $section) {
            $slides[] = $this->slide($section);
        }

        $caveat = $this->caveatSlide($report);

        if ($caveat !== null) {
            /*
             * Immediately after the figures it qualifies, not at the end. A caveat on the last slide is a caveat
             * read after the conversation has moved on.
             */
            $slides[] = $caveat;
        }

        return $slides;
    }

    private function slide(RenderedSection $section): Slide
    {
        $notes = [...$section->caveats];
        $notes[] = sprintf('Figures: %s.', $section->source->label());

        if ($section->isEmpty) {
            /*
             * Said, not skipped. A deck with a missing slide reads as an oversight; a slide saying nothing was
             * recorded reads as a fact — and "nothing recorded" is not a total of zero.
             */
            return new Slide(
                kind: SlideKind::Scorecard,
                title: $section->title,
                lead: 'Nothing was recorded for this period. That is not a total of zero.',
                notes: $notes,
            );
        }

        return match ($section->kind) {
            SectionKind::Lead => new Slide(
                kind: SlideKind::Scorecard,
                title: $section->title,
                lead: null,
                figures: array_map(
                    static fn (array $fact): SlideFigure => new SlideFigure(
                        label: $fact['label'],
                        value: $fact['text'] ?? 'Not available',
                        change: null,
                        changeIsGood: null,
                        sourceLabel: 'Recorded leads',
                        unavailableReason: $fact['text'] === null ? 'There was nothing to divide by.' : null,
                    ),
                    $section->lead?->facts() ?? [],
                ),
                notes: $notes,
            ),
            SectionKind::Store => new Slide(
                kind: SlideKind::Scorecard,
                title: $section->title,
                lead: null,
                figures: array_map(
                    static fn (StoreFigure $figure): SlideFigure => new SlideFigure(
                        label: $figure->label,
                        value: $figure->text() ?? 'Not available',
                        change: null,
                        changeIsGood: null,
                        sourceLabel: $section->store?->revenueSource->label() ?? $section->source->label(),
                        unavailableReason: $figure->value === null ? 'There were no orders to average.' : null,
                    ),
                    $section->store?->figures() ?? [],
                ),
                notes: $notes,
            ),
            SectionKind::Scorecard => new Slide(
                kind: SlideKind::Scorecard,
                title: $section->title,
                lead: null,
                figures: $this->figures($section),
                notes: $notes,
            ),
            SectionKind::Trend => new Slide(
                kind: SlideKind::Trend,
                title: $section->title,
                lead: null,
                series: $this->series($section),
                categories: array_map(
                    static fn (SeriesPoint $point): string => $point->date->format('Y-m-d'),
                    $section->series,
                ),
                table: $this->trendTable($section),
                notes: $notes,
            ),
            SectionKind::Breakdown => new Slide(
                kind: SlideKind::Breakdown,
                title: $section->title,
                lead: null,
                series: $this->rankingSeries($section),
                categories: array_map(
                    static fn (BreakdownRow $row): string => $row->label,
                    $section->rows,
                ),
                table: $this->rowTable($section),
                notes: $notes,
            ),
            SectionKind::Table => new Slide(
                kind: SlideKind::Table,
                title: $section->title,
                lead: null,
                table: $this->rowTable($section),
                notes: $notes,
            ),
        };
    }

    /** @return list<SlideFigure> */
    private function figures(RenderedSection $section): array
    {
        $figures = [];

        foreach ($section->metrics as $metric) {
            $value = $section->totals?->get($metric);

            if ($value === null) {
                continue;
            }

            $figures[] = new SlideFigure(
                label: $metric->label(),
                value: $this->text->value($value),
                change: $this->text->change($value),
                changeIsGood: $value->changeIsGood(),
                sourceLabel: $value->source->label(),
                unavailableReason: $value->unavailableReason,
            );
        }

        return $figures;
    }

    /** @return list<SlideSeries> */
    private function series(RenderedSection $section): array
    {
        $series = [];

        foreach ($section->metrics as $metric) {
            $points = [];
            $labels = [];

            foreach ($section->series as $point) {
                $value = $point->metrics->get($metric);

                /* Null stays null: a day with no delivery is a gap, not a line through the floor. */
                $points[] = $value?->value === null ? null : (float) $value->value;
                $labels[] = $value === null ? '—' : $this->text->value($value);
            }

            $series[] = new SlideSeries($metric->label(), $points, $labels);
        }

        return $series;
    }

    /** @return list<SlideSeries> */
    private function rankingSeries(RenderedSection $section): array
    {
        $metric = $section->metrics[0] ?? null;

        if ($metric === null) {
            return [];
        }

        $points = [];
        $labels = [];

        foreach ($section->rows as $row) {
            $value = $row->metrics->get($metric);

            $points[] = $value?->value === null ? null : (float) $value->value;
            $labels[] = $value === null ? '—' : $this->text->value($value);
        }

        return [new SlideSeries($metric->label(), $points, $labels)];
    }

    private function trendTable(RenderedSection $section): SlideTable
    {
        $rows = [];

        foreach ($section->series as $point) {
            $cells = [];

            foreach ($section->metrics as $metric) {
                $value = $point->metrics->get($metric);
                $cells[] = $value === null ? '—' : $this->text->value($value);
            }

            $rows[] = ['label' => $point->date->format('Y-m-d'), 'cells' => $cells];
        }

        return new SlideTable(
            ['Date', ...array_map(static fn (Metric $metric): string => $metric->label(), $section->metrics)],
            $rows,
        );
    }

    private function rowTable(RenderedSection $section): SlideTable
    {
        $rows = [];

        foreach ($section->rows as $row) {
            $cells = [];

            foreach ($section->metrics as $metric) {
                $value = $row->metrics->get($metric);
                $cells[] = $value === null ? '—' : $this->text->value($value);
            }

            $rows[] = ['label' => $row->label, 'cells' => $cells];
        }

        return new SlideTable(
            [
                $section->dimension?->label() ?? 'Row',
                ...array_map(static fn (Metric $metric): string => $metric->label(), $section->metrics),
            ],
            $rows,
        );
    }

    /**
     * What the figures do not say.
     *
     * Assembled from the report's own refusals rather than written here: the comparability reason, each section's
     * caveats, and the source statement. Null when there is genuinely nothing to qualify — a slide saying "no
     * caveats" would be a slide that teaches a reader to skip the caveat slide.
     */
    private function caveatSlide(RenderedReport $report): ?Slide
    {
        $points = [];

        if ($report->comparability->wasRequested() && ! $report->comparability->isComparable) {
            $reason = $report->comparability->reason;

            if ($reason !== null) {
                $points[] = $reason;
            }
        }

        if ($report->isPeriodIncomplete) {
            $points[] = 'This period is still running, so these figures will move for the rest of it.';
        }

        foreach ($report->sections as $section) {
            foreach ($section->caveats as $caveat) {
                if (! in_array($caveat, $points, true)) {
                    $points[] = $caveat;
                }
            }
        }

        if ($report->source->requiresLabel()) {
            $points[] = sprintf(
                'The weakest figure in this deck is %s. Read every number here as that.',
                $report->source->label(),
            );
        }

        if ($points === []) {
            return null;
        }

        return new Slide(
            kind: SlideKind::Caveat,
            title: SlideKind::Caveat->label(),
            lead: 'Read out, not skipped. Each of these changes what a figure on the previous slides means.',
            paragraphs: $points,
        );
    }
}
