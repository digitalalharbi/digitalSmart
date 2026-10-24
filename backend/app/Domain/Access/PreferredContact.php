<?php

declare(strict_types=1);

namespace App\Domain\Access;

enum PreferredContact: string
{
    case Email = 'email';
    case Phone = 'phone';
    case WhatsApp = 'whatsapp';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
