<?php

declare(strict_types=1);

namespace App\Application\Presentation\DTO;

/** A table, formatted. Every cell is a string: see the note on `Slide`. */
final readonly class SlideTable
{
    /**
     * @param  list<string>  $columns
     * @param  list<array{label: string, cells: list<string>}>  $rows
     */
    public function __construct(
        public array $columns,
        public array $rows,
    ) {}
}
