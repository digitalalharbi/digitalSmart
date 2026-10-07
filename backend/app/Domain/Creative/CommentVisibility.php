<?php

declare(strict_types=1);

namespace App\Domain\Creative;

/**
 * Who a comment is for.
 *
 * Two values, and the distinction is load-bearing rather than a courtesy. An agency's internal note on a
 * client's creative — "the client hated the last one, keep it safe", "we are behind because the designer
 * is on leave" — reaching that client is the kind of failure that ends a relationship. So visibility is a
 * required field with no default, and the client-facing surfaces filter on it rather than trusting a
 * caller to remember.
 */
enum CommentVisibility: string
{
    /** The agency only. The default a careful person wants, and still never implicit. */
    case Internal = 'internal';

    /** Written to be read by the client, and visible in the portal. */
    case Client = 'client';

    public function isVisibleToClient(): bool
    {
        return $this === self::Client;
    }

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Internal note',
            self::Client => 'Visible to the client',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
