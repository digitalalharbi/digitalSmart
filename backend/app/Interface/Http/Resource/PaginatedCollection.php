<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource;

use App\Application\Shared\DTO\Paginated;
use Illuminate\Http\JsonResponse;

/** Wraps a Paginated result in the API's standard envelope. */
final readonly class PaginatedCollection
{
    /**
     * @param  Paginated<object>  $page
     * @param  callable(object): array<string, mixed>  $transform
     */
    public static function response(Paginated $page, callable $transform): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map($transform, $page->items),
            'meta' => [
                'total' => $page->total,
                'page' => $page->page,
                'per_page' => $page->perPage,
                'last_page' => $page->lastPage(),
                'has_more' => $page->hasMore(),
            ],
        ]);
    }
}
