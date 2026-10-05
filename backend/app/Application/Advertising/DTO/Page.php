<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

/**
 * One page of provider results, plus where to resume.
 *
 * Every platform paginates differently — Meta returns opaque `after` cursors,
 * Google a `next_page_token`, TikTok a page number, LinkedIn an offset. The
 * cursor here is an opaque string the adapter understands and nothing else
 * interprets.
 *
 * @template T
 */
final readonly class Page
{
    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor = null,
        public int $apiCalls = 1,
    ) {}

    /**
     * @param  list<T>  $items
     * @return self<T>
     */
    public static function last(array $items, int $apiCalls = 1): self
    {
        return new self($items, null, $apiCalls);
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }
}
