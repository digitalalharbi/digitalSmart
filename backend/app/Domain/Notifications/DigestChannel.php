<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Where a digest can be delivered, and whether it can be today.
 *
 * In-app is the one channel that exists. Email and WhatsApp are offered so a person can say what they want, and
 * each one says plainly that it is waiting on a credential nobody has supplied — a preference that silently did
 * nothing would be a promise the product cannot keep. Nothing is ever marked sent that was not.
 */
enum DigestChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::InApp => 'In the app',
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
        };
    }

    /** `available`, or `awaiting_credentials` — the project's own word for a thing built and not yet connected. */
    public function availability(): string
    {
        return $this === self::InApp ? 'available' : 'awaiting_credentials';
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
