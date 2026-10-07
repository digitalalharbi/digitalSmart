<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * Who a message on a client request is for.
 *
 * **No default anywhere.** The same rule a creative comment follows, for the same reason: an agency's internal
 * note about a client reaching that client ends a relationship, and a default is exactly how it would happen. A
 * caller that omits the audience is refused, not helped.
 *
 * Its own enum rather than reusing `Creative\CommentVisibility`: identical today, and coupling two modules so
 * that adding an audience to one silently adds it to the other is not a saving worth having on four lines.
 */
enum MessageAudience: string
{
    /** The agency only. Never leaves the building. */
    case Internal = 'internal';

    /** The client sees it. */
    case Client = 'client';

    public function isVisibleToClient(): bool
    {
        return $this === self::Client;
    }

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Internal only',
            self::Client => 'The client will see this',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
