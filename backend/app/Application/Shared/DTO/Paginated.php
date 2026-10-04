<?php

declare(strict_types=1);

namespace App\Application\Shared\DTO;

/**
 * A page of results.
 *
 * Read-only, so the template is covariant: a Paginated<ClientView> is usable
 * wherever a Paginated<object> is expected.
 *
 * @template-covariant T
 */
final readonly class Paginated
{
    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function lastPage(): int
    {
        return $this->perPage > 0 ? (int) max(1, ceil($this->total / $this->perPage)) : 1;
    }

    public function hasMore(): bool
    {
        return $this->page < $this->lastPage();
    }
}
