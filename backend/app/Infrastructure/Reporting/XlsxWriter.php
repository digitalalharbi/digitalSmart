<?php

declare(strict_types=1);

namespace App\Infrastructure\Reporting;

use App\Application\Analytics\DTO\MetricValue;
use App\Application\Reporting\Contract\ReportWorkbookWriter;
use App\Application\Reporting\DTO\RenderedReport;
use App\Application\Reporting\DTO\RenderedSection;
use App\Domain\Analytics\MetricFormat;
use App\Domain\Reporting\SectionKind;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * A report as a real spreadsheet.
 *
 * **What this adds over the CSV, which already exists: types.** In a CSV every cell is text, so a column of
 * spend arrives in Excel as text and the first thing anybody does is retype it; a date arrives as a string and
 * sorts alphabetically. Here a number is a number, a date is a date, money carries a currency format and a
 * percentage carries a percentage format — which is the entire reason somebody asks for XLSX rather than CSV.
 *
 * **The caveats keep travelling with the figures**, and in a workbook that is harder rather than easier: a
 * reader opens whichever sheet they were sent to and never sees sheet one. So the source state and the
 * comparability verdict are repeated at the top of **every** section's sheet, not only on the cover. A file
 * whose warnings live on a tab nobody opens is a file with no warnings.
 *
 * **An unavailable figure is written as its reason, as text, in the numeric column.** The alternative — a blank
 * cell — is summed as zero by whoever opens it next, and this product's whole position is that a missing figure
 * is not a zero. A text cell in a numeric column is visibly not a number, which is the point: it refuses to be
 * added up silently.
 *
 * **Money is written in major units** with the currency in the column heading and in the cell's own format.
 * Minor units in a column called "Spend" is a figure somebody will quote; `MetricValue` carries the exponent.
 */
final readonly class XlsxWriter implements ReportWorkbookWriter
{
    /** Excel's own limit on a sheet name, and it truncates silently rather than failing. */
    private const int SHEET_NAME_LIMIT = 31;

    /** Characters Excel forbids in a sheet name. */
    private const string FORBIDDEN_IN_SHEET_NAME = '\\/?*[]:';

    public function extension(): string
    {
        return 'xlsx';
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function write(RenderedReport $report): string
    {
        $workbook = new Spreadsheet;

        $properties = $workbook->getProperties();
        $properties->setTitle($report->name);
        $properties->setCreator('digitalSmart');
        /*
         * The source state in the file's own properties as well as in its cells. A workbook built from sandbox
         * fixtures and one built from a client's real spend are identical as numbers, and only one may be acted
         * on — so the claim is attached to the file and not only to a cell somebody can delete.
         */
        $properties->setDescription(sprintf(
            '%s — %s to %s. Data source: %s. Taken %s.',
            $report->name,
            $report->period->start->format('Y-m-d'),
            $report->period->end->format('Y-m-d'),
            $report->source->label(),
            $report->renderedAt->format(DateTimeImmutable::ATOM),
        ));

        $cover = $workbook->getActiveSheet();
        $cover->setTitle('Report');
        $this->cover($cover, $report);

        $used = ['Report'];

        foreach ($report->sections as $index => $section) {
            $sheet = $workbook->createSheet();
            $sheet->setTitle($this->sheetName($section->title, $index, $used));
            $this->section($sheet, $section, $report);
        }

        $workbook->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'ds-report-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the workbook.');
        }

        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();

        return $path;
    }

    /** The cover: everything the interface showed around the figures. */
    private function cover(Worksheet $sheet, RenderedReport $report): void
    {
        $rows = [
            [$report->name, null],
            ['Client', $report->clientName],
            [
                'Period',
                $report->period->start->format('Y-m-d').' to '.$report->period->end->format('Y-m-d'),
            ],
            ['Data source', $report->source->label()],
            ['Taken', $report->renderedAt->format(DateTimeImmutable::ATOM)],
            ['Mode', $report->mode->label()],
        ];

        if ($report->isPeriodIncomplete) {
            $rows[] = ['Note', 'This period is still running. The figures will move.'];
        }

        if ($report->comparability->wasRequested()) {
            $rows[] = ['Compared with', $report->comparison->label()];

            if (! $report->comparability->isComparable && $report->comparability->reason !== null) {
                // The sentence that replaces the percentages, carried into the file rather than left behind.
                $rows[] = ['Comparison warning', $report->comparability->reason];
            }
        }

        $line = 1;

        foreach ($rows as $row) {
            if ($row[1] === null && $line > 1) {
                continue;
            }

            $sheet->setCellValueExplicit('A'.$line, (string) $row[0], DataType::TYPE_STRING);

            if ($row[1] !== null) {
                $sheet->setCellValueExplicit('B'.$line, (string) $row[1], DataType::TYPE_STRING);
            }

            $line++;
        }

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1:A'.($line - 1))->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(72);
        $sheet->getStyle('B1:B'.($line - 1))->getAlignment()->setWrapText(true);
    }

    private function section(Worksheet $sheet, RenderedSection $section, RenderedReport $report): void
    {
        $line = 1;

        $sheet->setCellValueExplicit('A'.$line, $section->title, DataType::TYPE_STRING);
        $sheet->getStyle('A'.$line)->getFont()->setBold(true)->setSize(13);
        $line += 2;

        /*
         * Repeated per sheet, deliberately. A reader opens the tab they were pointed at; a file whose source
         * state lives only on the cover is a file whose source state nobody read.
         */
        foreach ($this->notes($section, $report) as $note) {
            $sheet->setCellValueExplicit('A'.$line, $note[0], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.$line, $note[1], DataType::TYPE_STRING);
            $sheet->getStyle('A'.$line)->getFont()->setBold(true);
            $line++;
        }

        $line++;

        if ($section->isEmpty) {
            /*
             * Said, not left blank. A sheet with no rows and no sentence reads as an export that failed, and the
             * next person re-runs it rather than learning there was nothing to report.
             */
            $sheet->setCellValueExplicit(
                'A'.$line,
                'Nothing was recorded for this section in this period.',
                DataType::TYPE_STRING,
            );

            $this->fit($sheet, 1);

            return;
        }

        $columns = match ($section->kind) {
            SectionKind::Scorecard, SectionKind::Traffic => $this->scorecard($sheet, $section, $line),
            SectionKind::Attribution => $this->attribution($sheet, $section, $line),
            SectionKind::Trend => $this->trend($sheet, $section, $line),
            SectionKind::Store => $this->store($sheet, $section, $line),
            SectionKind::Lead => $this->lead($sheet, $section, $line),
            SectionKind::Tracking => $this->tracking($sheet, $section, $line),
            default => $this->breakdown($sheet, $section, $line),
        };

        $this->fit($sheet, $columns);
    }

    /**
     * The notes above every section's table.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function notes(RenderedSection $section, RenderedReport $report): array
    {
        $notes = [
            ['Data source', $section->source->label()],
            [
                'Period',
                $report->period->start->format('Y-m-d').' to '.$report->period->end->format('Y-m-d'),
            ],
        ];

        if (! $report->comparability->isComparable && $report->comparability->reason !== null) {
            $notes[] = ['Comparison warning', $report->comparability->reason];
        }

        foreach ($section->caveats as $caveat) {
            $notes[] = ['Note', $caveat];
        }

        return $notes;
    }

    /** @return int the number of columns written */
    private function tracking(Worksheet $sheet, RenderedSection $section, int $line): int
    {
        $table = $section->tracking;

        if ($table === null) {
            return 1;
        }

        $this->headings($sheet, $line, array_map(static fn (array $c): string => $c['label'], $table->columns));
        $line++;

        foreach ($table->rows as $row) {
            foreach ($table->columns as $i => $column) {
                $cell = Coordinate::stringFromColumnIndex($i + 1).$line;
                $value = $row[$column['key']] ?? null;

                if ($value === null) {
                    /* Visibly not a number, so it refuses to be summed. */
                    $sheet->setCellValueExplicit($cell, 'Not reported', DataType::TYPE_STRING);
                } elseif (is_int($value) || is_float($value)) {
                    $sheet->setCellValue($cell, $value);
                } else {
                    $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                }
            }

            $line++;
        }

        return count($table->columns);
    }

    /** @return int the number of columns written */
    private function scorecard(Worksheet $sheet, RenderedSection $section, int $line): int
    {
        $this->headings($sheet, $line, ['Metric', 'Value', 'Comparison', 'Change']);
        $line++;

        foreach ($section->metrics as $metric) {
            $value = $section->totals?->get($metric);

            if ($value === null) {
                continue;
            }

            $sheet->setCellValueExplicit('A'.$line, $this->heading($value), DataType::TYPE_STRING);
            $this->figure($sheet, 'B'.$line, $value->value, $value);
            $this->figure($sheet, 'C'.$line, $value->comparisonValue, $value, true);

            $ratio = $value->changeRatio();

            if ($ratio !== null) {
                /*
                 * Written as a ratio with a percentage format rather than as a number times a hundred, so the
                 * cell *is* a percentage in Excel and stays one when somebody charts it.
                 */
                $sheet->setCellValue('D'.$line, $ratio);
                $sheet->getStyle('D'.$line)
                    ->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
            }

            $line++;
        }

        return 4;
    }

    /** @return int the number of columns written */
    private function store(Worksheet $sheet, RenderedSection $section, int $line): int
    {
        $this->headings($sheet, $line, ['Figure', 'Value']);
        $line++;

        foreach ($section->store?->figures() ?? [] as $figure) {
            $label = $figure->currency === null ? $figure->label : sprintf('%s (%s)', $figure->label, $figure->currency);
            $sheet->setCellValueExplicit('A'.$line, $label, DataType::TYPE_STRING);

            $major = $figure->major();

            if ($major === null) {
                /* The words, in the numeric column: visibly not a number, and refusing to be summed. */
                $sheet->setCellValueExplicit('B'.$line, 'Not available', DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue('B'.$line, $major);
                $decimals = $figure->decimals();
                $format = $decimals === 0 ? '#,##0' : '#,##0.'.str_repeat('0', $decimals);
                $sheet->getStyle('B'.$line)->getNumberFormat()->setFormatCode(
                    $figure->currency === null ? $format : sprintf('%s" %s"', $format, $figure->currency),
                );
            }

            $line++;
        }

        return 2;
    }

    /** @return int the number of columns written */
    private function attribution(Worksheet $sheet, RenderedSection $section, int $line): int
    {
        $this->headings($sheet, $line, ['Figure', 'Value']);
        $line++;

        foreach ($section->attribution?->facts() ?? [] as $fact) {
            $sheet->setCellValueExplicit('A'.$line, $fact['label'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.$line, $fact['text'] ?? 'Not available', DataType::TYPE_STRING);
            $line++;
        }

        $line++;
        $this->headings($sheet, $line, ['Campaign', 'Conversions', 'Revenue', 'Currency', 'Evidence']);
        $line++;

        foreach ($section->attribution === null ? [] : $section->attribution->rows as $row) {
            $sheet->setCellValueExplicit('A'.$line, $row->label, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.$line, (string) $row->conversions, DataType::TYPE_NUMERIC);
            /* The words, in the numeric column: where it is visibly not a number it refuses to be summed. */
            if ($row->revenueMinor === null) {
                $sheet->setCellValueExplicit('C'.$line, 'Not available', DataType::TYPE_STRING);
            } else {
                $sheet->setCellValueExplicit('C'.$line, (string) ($row->revenueMinor / 100), DataType::TYPE_NUMERIC);
            }
            $sheet->setCellValueExplicit('D'.$line, $row->currency ?? '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('E'.$line, $row->evidence->value, DataType::TYPE_STRING);
            $line++;
        }

        return 5;
    }

    /** @return int the number of columns written */
    private function lead(Worksheet $sheet, RenderedSection $section, int $line): int
    {
        $this->headings($sheet, $line, ['Figure', 'Value']);
        $line++;

        foreach ($section->lead?->facts() ?? [] as $fact) {
            $sheet->setCellValueExplicit('A'.$line, $fact['label'], DataType::TYPE_STRING);
            /* The words, in the value column: a rate with nothing to divide by is not a zero. */
            $sheet->setCellValueExplicit('B'.$line, $fact['text'] ?? 'Not available', DataType::TYPE_STRING);
            $line++;
        }

        return 2;
    }

    /** @return int the number of columns written */
    private function trend(Worksheet $sheet, RenderedSection $section, int $line): int
    {
        $headings = ['Date'];

        foreach ($section->metrics as $metric) {
            $first = $section->series[0]->metrics->get($metric) ?? null;
            $headings[] = $first === null ? $metric->label() : $this->heading($first);
        }

        $this->headings($sheet, $line, $headings);
        $line++;

        foreach ($section->series as $point) {
            /*
             * A real date, with a date format. In the CSV this is a string, and a string sorts alphabetically —
             * which is fine until a period crosses a year.
             */
            $sheet->setCellValue('A'.$line, Date::PHPToExcel($point->date));
            $sheet->getStyle('A'.$line)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);

            $column = 1;

            foreach ($section->metrics as $metric) {
                $value = $point->metrics->get($metric);
                $cell = $this->column(++$column).$line;

                if ($value !== null) {
                    $this->figure($sheet, $cell, $value->value, $value);
                }
            }

            $line++;
        }

        return count($headings);
    }

    /** @return int the number of columns written */
    private function breakdown(Worksheet $sheet, RenderedSection $section, int $line): int
    {
        $headings = [$section->dimension?->label() ?? 'Row'];

        foreach ($section->metrics as $metric) {
            $first = $section->rows[0]->metrics->get($metric) ?? null;
            $headings[] = $first === null ? $metric->label() : $this->heading($first);
        }

        $this->headings($sheet, $line, $headings);
        $line++;

        foreach ($section->rows as $row) {
            $sheet->setCellValueExplicit('A'.$line, $row->label, DataType::TYPE_STRING);

            $column = 1;

            foreach ($section->metrics as $metric) {
                $value = $row->metrics->get($metric);
                $cell = $this->column(++$column).$line;

                if ($value !== null) {
                    $this->figure($sheet, $cell, $value->value, $value);
                }
            }

            $line++;
        }

        return count($headings);
    }

    /**
     * One figure, typed.
     *
     * The unavailable case is the important one: the reason goes in as **text**, in a numeric column, where it
     * is visibly not a number. A blank cell would be summed as zero by the next person to open the file.
     */
    private function figure(
        Worksheet $sheet,
        string $cell,
        int|float|null $raw,
        MetricValue $value,
        bool $isComparison = false,
    ): void {
        if ($raw === null) {
            if (! $isComparison) {
                $sheet->setCellValueExplicit(
                    $cell,
                    $value->unavailableReason ?? 'Not reported',
                    DataType::TYPE_STRING,
                );
            }

            return;
        }

        if ($value->format === MetricFormat::Currency) {
            // Major units, and the currency in the cell's own format rather than only in the heading.
            $sheet->setCellValue($cell, $raw / 100);
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(
                $value->currency === null
                    ? '#,##0.00'
                    : sprintf('#,##0.00" %s"', $value->currency),
            );

            return;
        }

        if ($value->format === MetricFormat::Percentage) {
            /*
             * The read model publishes a percentage as a number out of a hundred, so it is divided here to make
             * the cell a true percentage — the one place in this class where a figure is transformed, and the
             * format makes the transformation visible rather than hidden.
             */
            $sheet->setCellValue($cell, (float) $raw / 100);
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);

            return;
        }

        $sheet->setCellValue($cell, $raw);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(
            is_float($raw) ? NumberFormat::FORMAT_NUMBER_00 : NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
        );
    }

    /** @param list<string> $headings */
    private function headings(Worksheet $sheet, int $line, array $headings): void
    {
        foreach ($headings as $index => $heading) {
            $sheet->setCellValueExplicit(
                $this->column($index + 1).$line,
                $heading,
                DataType::TYPE_STRING,
            );
        }

        $range = sprintf('A%d:%s%d', $line, $this->column(count($headings)), $line);
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        /* Frozen, so the headings stay visible on a ranking long enough to scroll. */
        $sheet->freezePane('A'.($line + 1));
    }

    /** The metric's name, with its currency where it has one, so a column of money says which money. */
    private function heading(MetricValue $value): string
    {
        return $value->currency === null
            ? $value->metric->label()
            : sprintf('%s (%s)', $value->metric->label(), $value->currency);
    }

    private function column(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    private function fit(Worksheet $sheet, int $columns): void
    {
        $sheet->getColumnDimension('A')->setWidth(34);

        for ($index = 2; $index <= max(2, $columns); $index++) {
            $sheet->getColumnDimension($this->column($index))->setWidth(18);
        }
    }

    /**
     * A sheet name Excel will accept, and that is unique in the workbook.
     *
     * Two sections can legitimately share a title — "By campaign" twice, over different metrics — and Excel
     * refuses a duplicate sheet name rather than disambiguating it. The index is appended only when it has to
     * be, so the common case reads as the section's own title.
     *
     * @param  list<string>  $used
     */
    private function sheetName(string $title, int $index, array &$used): string
    {
        $clean = str_replace(str_split(self::FORBIDDEN_IN_SHEET_NAME), ' ', $title);
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
        $clean = $clean === '' ? 'Section '.($index + 1) : $clean;
        $name = mb_substr($clean, 0, self::SHEET_NAME_LIMIT);

        if (in_array($name, $used, true)) {
            $suffix = ' ('.($index + 1).')';
            $name = mb_substr($clean, 0, self::SHEET_NAME_LIMIT - mb_strlen($suffix)).$suffix;
        }

        $used[] = $name;

        return $name;
    }
}
