<?php

declare(strict_types=1);

namespace App\Application\Reporting\Contract;

use App\Application\Reporting\DTO\RenderedReport;

/**
 * Writes a rendered report as a spreadsheet file.
 *
 * A contract rather than a direct call, for the same reason the deck writer is one: the library that produces
 * the file is an infrastructure detail, and the use case that asks for one should not have to know which.
 */
interface ReportWorkbookWriter
{
    /** The file extension this writer produces, without a dot. */
    public function extension(): string;

    public function contentType(): string;

    /** @return string the absolute path of the written file, which the caller owns and must delete */
    public function write(RenderedReport $report): string;
}
