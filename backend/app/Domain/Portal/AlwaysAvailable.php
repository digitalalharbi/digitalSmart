<?php

declare(strict_types=1);

namespace App\Domain\Portal;

/**
 * A surface a client can always reach, which is therefore not a setting.
 *
 * The mirror of {@see WithheldModule}. That enum says why something will never be exposed; this one says why
 * something cannot be withheld — and both exist so that the answer does not depend on who is asked.
 *
 * It has one case today and the shape is deliberate: the moment a second surface earns this argument, it goes here
 * rather than becoming a toggle that silently does nothing. The disjointness of the three lists is held by a test.
 */
enum AlwaysAvailable: string
{
    case Requests = 'requests';

    public function label(): string
    {
        return match ($this) {
            self::Requests => 'Requests',
        };
    }

    public function because(): string
    {
        return match ($this) {
            self::Requests => 'A client writes half of this thread, and a person is entitled to read a '
                .'conversation they are part of. A switch here would either control nothing, or let an agency '
                .'withhold the answer to a question the client themselves asked. The agency\'s internal notes on '
                .'a request are a separate matter, and they are excluded from the client\'s read in SQL rather '
                .'than by a setting.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
