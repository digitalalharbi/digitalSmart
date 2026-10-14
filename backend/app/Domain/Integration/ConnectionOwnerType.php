<?php

declare(strict_types=1);

namespace App\Domain\Integration;

enum ConnectionOwnerType: string
{
    /** The agency's own credentials: its manager account, its Business Manager, its developer token. */
    case Agency = 'agency';

    /** Credentials a client granted directly, which leave with the client. */
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Agency => 'Agency',
            self::Client => 'Client',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
