<?php

declare(strict_types=1);

namespace App\Application\Shared\DTO;

/** Requested page, clamped so a caller cannot ask for an unbounded result set. */
final readonly class Pagination
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    public int $page;

    public int $perPage;

    public function __construct(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE)
    {
        $this->page = max(1, $page);
        $this->perPage = min(self::MAX_PER_PAGE, max(1, $perPage));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
