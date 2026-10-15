<?php

declare(strict_types=1);

namespace App\Domain\Export;

/** What can be exported in the background. Each kind names the thing it renders and the file it produces. */
enum ExportKind: string
{
    case ReportCsv = 'report_csv';
    case ReportXlsx = 'report_xlsx';

    public function extension(): string
    {
        return match ($this) {
            self::ReportCsv => 'csv',
            self::ReportXlsx => 'xlsx',
        };
    }

    public function contentType(): string
    {
        return match ($this) {
            self::ReportCsv => 'text/csv; charset=UTF-8',
            self::ReportXlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
