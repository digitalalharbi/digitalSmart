<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Reporting;

use App\Application\Analytics\DTO\MetricValue;
use App\Application\Reporting\DTO\RenderedReport;
use App\Application\Reporting\DTO\RenderedSection;
use App\Domain\Analytics\MetricFormat;
use App\Domain\Reporting\SectionKind;
use DateTimeImmutable;

/**
 * A report as CSV.
 *
 * **The caveats travel with the figures.** A CSV that dropped the source states, the period, the comparability
 * verdict and the per-section caveats would be the most dangerous artefact this product makes: a file somebody
 * pastes into a deck, where every warning the interface showed has fallen off. So the export opens with a header
 * block naming all of it, and each section's caveats are written above its rows.
 *
 * **Money is written in major units with its currency in the column heading**, not in minor units. A spreadsheet
 * is read by people, and 5 000 000 halalas in a column called "spend" is a number somebody will quote. The
 * precision of the conversion is the currency's own exponent, which `MetricValue` already carries.
 *
 * **An unavailable figure is written as its reason, not as an empty cell or a zero.** An empty cell in a
 * spreadsheet gets summed as zero by whoever opens it next.
 */
final readonly class ReportCsv
{
    public static function render(RenderedReport $report): string
    {
        $lines = [];

        $lines[] = self::row([$report->name]);

        if ($report->clientName !== null) {
            $lines[] = self::row(['Client', $report->clientName]);
        }

        $lines[] = self::row([
            'Period',
            $report->period->start->format('Y-m-d').' to '.$report->period->end->format('Y-m-d'),
        ]);

        if ($report->isPeriodIncomplete) {
            $lines[] = self::row(['Note', 'This period is still running. The figures will move.']);
        }

        /*
         * The source state, in the file. A CSV built from sandbox fixtures and one built from a client's real spend
         * are identical as numbers, and only one of them may be acted on.
         */
        $lines[] = self::row(['Data source', $report->source->label()]);
        $lines[] = self::row(['Taken', $report->renderedAt->format(DateTimeImmutable::ATOM)]);
        $lines[] = self::row(['Mode', $report->mode->label()]);

        if ($report->comparability->wasRequested()) {
            $lines[] = self::row(['Compared with', $report->comparison->label()]);

            if (! $report->comparability->isComparable && $report->comparability->reason !== null) {
                // The sentence that replaces the percentages, carried into the file rather than left behind.
                $lines[] = self::row(['Comparison warning', $report->comparability->reason]);
            }
        }

        $lines[] = '';

        foreach ($report->sections as $section) {
            foreach (self::section($section) as $line) {
                $lines[] = $line;
            }

            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /** @return list<string> */
    private static function section(RenderedSection $section): array
    {
        $lines = [self::row([$section->title]), self::row(['Data source', $section->source->label()])];

        foreach ($section->caveats as $caveat) {
            $lines[] = self::row(['Note', $caveat]);
        }

        if ($section->isEmpty) {
            /*
             * Said, not left blank. A section with no rows and no sentence reads as an export that failed, and the
             * next person re-runs it rather than learning there was nothing to report.
             */
            $lines[] = self::row(['Nothing was recorded for this section in this period.']);

            return $lines;
        }

        if ($section->kind === SectionKind::Scorecard && $section->totals !== null) {
            $lines[] = self::row(['Metric', 'Value', 'Comparison', 'Change']);

            foreach ($section->metrics as $metric) {
                $value = $section->totals->get($metric);

                if ($value === null) {
                    continue;
                }

                $lines[] = self::row([
                    self::heading($value),
                    self::cell($value),
                    self::number($value->comparisonValue, $value),
                    self::change($value),
                ]);
            }

            return $lines;
        }

        if ($section->kind === SectionKind::Trend) {
            $headings = ['Date'];

            foreach ($section->metrics as $metric) {
                $first = $section->series[0]->metrics->get($metric) ?? null;
                $headings[] = $first === null ? $metric->label() : self::heading($first);
            }

            $lines[] = self::row($headings);

            foreach ($section->series as $point) {
                $cells = [$point->date->format('Y-m-d')];

                foreach ($section->metrics as $metric) {
                    $value = $point->metrics->get($metric);
                    $cells[] = $value === null ? '' : self::cell($value);
                }

                $lines[] = self::row($cells);
            }

            return $lines;
        }

        $headings = [$section->dimension?->label() ?? 'Row'];

        foreach ($section->metrics as $metric) {
            $first = $section->rows[0]->metrics->get($metric) ?? null;
            $headings[] = $first === null ? $metric->label() : self::heading($first);
        }

        $lines[] = self::row($headings);

        foreach ($section->rows as $row) {
            $cells = [$row->label];

            foreach ($section->metrics as $metric) {
                $value = $row->metrics->get($metric);
                $cells[] = $value === null ? '' : self::cell($value);
            }

            $lines[] = self::row($cells);
        }

        return $lines;
    }

    /** The metric's name, with its currency where it has one, so a column of money says which money. */
    private static function heading(MetricValue $value): string
    {
        return $value->currency === null
            ? $value->metric->label()
            : sprintf('%s (%s)', $value->metric->label(), $value->currency);
    }

    private static function cell(MetricValue $value): string
    {
        if ($value->value === null) {
            /*
             * The reason, not an empty cell. An empty cell in a spreadsheet is summed as zero by whoever opens it
             * next, and this product's whole position is that a missing figure is not a zero.
             */
            return $value->unavailableReason ?? 'Not reported';
        }

        return self::number($value->value, $value);
    }

    private static function number(int|float|null $raw, MetricValue $value): string
    {
        if ($raw === null) {
            return '';
        }

        if ($value->format === MetricFormat::Currency) {
            /*
             * Major units. A spreadsheet is read by people, and five million halalas in a column called "spend" is
             * a number somebody will quote.
             */
            return number_format($raw / 100, 2, '.', '');
        }

        if ($value->format === MetricFormat::Percentage) {
            return number_format((float) $raw, 2, '.', '');
        }

        return is_float($raw) ? number_format($raw, 2, '.', '') : (string) $raw;
    }

    private static function change(MetricValue $value): string
    {
        $ratio = $value->changeRatio();

        // Blank rather than zero: no comparison and no change are different facts.
        return $ratio === null ? '' : number_format($ratio * 100, 1, '.', '').'%';
    }

    /** @param list<string> $cells */
    private static function row(array $cells): string
    {
        $escaped = array_map(static function (string $cell): string {
            /*
             * Quoted whenever it could be misread, and a leading `=`, `+`, `-` or `@` is prefixed with an
             * apostrophe. Those four characters make a spreadsheet treat a cell as a formula, and a client's report
             * is not a place to find out that a campaign named "=cmd" executes something.
             */
            $guarded = preg_match('/^[=+\-@]/', $cell) === 1 ? "'".$cell : $cell;

            return '"'.str_replace('"', '""', $guarded).'"';
        }, $cells);

        return implode(',', $escaped);
    }
}
