<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

use App\Domain\Reporting\TrackingView;

/**
 * One view of a client's first-party tracking, as a table.
 *
 * A table because every view is: a row per link, form, funnel step, follow-up figure or health check. A cell is a number, a
 * word or `null` — and `null` is "not reported", rendered as such, never as zero. There is no identifier in a cell: a link is
 * named by its label, never its address or token, and a lead is never named at all.
 */
final readonly class TrackingSection
{
    /**
     * @param  list<array{key: string, label: string}>  $columns
     * @param  list<array<string, scalar|null>>  $rows
     */
    public function __construct(
        public TrackingView $view,
        public array $columns,
        public array $rows,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
