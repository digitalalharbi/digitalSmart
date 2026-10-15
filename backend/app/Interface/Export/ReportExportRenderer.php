<?php

declare(strict_types=1);

namespace App\Interface\Export;

use App\Application\Export\Contract\ExportRenderer;
use App\Application\Export\DTO\RenderedFile;
use App\Application\Reporting\Contract\ReportWorkbookWriter;
use App\Application\Reporting\Query\GetReport;
use App\Domain\Export\ExportKind;
use App\Interface\Http\Resource\Reporting\ReportCsv;
use RuntimeException;

/**
 * Renders a report to a file by the same two paths the synchronous download uses, so a queued CSV and a
 * direct CSV can never disagree about what a report says.
 */
final readonly class ReportExportRenderer implements ExportRenderer
{
    public function __construct(
        private GetReport $report,
        private ReportWorkbookWriter $workbook,
    ) {}

    public function render(ExportKind $kind, string $subjectId): RenderedFile
    {
        $rendered = ($this->report)($subjectId);
        $safe = preg_replace('/[^A-Za-z0-9 \-_]/', '', $rendered->name.' '.$rendered->period->start->format('Y-m-d')) ?? 'report';
        $name = (trim($safe) === '' ? 'report' : trim($safe)).'.'.$kind->extension();

        if ($kind === ExportKind::ReportCsv) {
            return new RenderedFile($name, $kind->contentType(), ReportCsv::render($rendered));
        }

        $path = $this->workbook->write($rendered);

        try {
            $bytes = file_get_contents($path);
        } finally {
            @unlink($path);
        }

        if ($bytes === false) {
            throw new RuntimeException('The workbook was written but could not be read back.');
        }

        return new RenderedFile($name, $kind->contentType(), $bytes);
    }
}
