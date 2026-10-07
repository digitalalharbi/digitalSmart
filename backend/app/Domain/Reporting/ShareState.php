<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use DateTimeImmutable;

/**
 * Whether a shared report link still works.
 *
 * Three rules this enum carries, and each has a version that an agency ships by accident:
 *
 *  - **A link expires.** A report link with no end is a credential with no end: it outlives the engagement, the
 *    account manager and sometimes the relationship. Every share gets a date.
 *  - **A revoked link is revoked immediately**, not on next expiry. Somebody revokes a link because something went
 *    to the wrong address, and "it will stop working next month" is not an answer.
 *  - **An expired link says it expired.** A 404 on a link a client was sent reads as the agency's site being
 *    broken, and the client asks for it again rather than being told it lapsed.
 */
enum ShareState: string
{
    case Active = 'active';

    case Revoked = 'revoked';

    /**
     * Derived rather than stored.
     *
     * Deliberately not a column: a stored `expired` would need something to write it, and whatever that is would
     * be the thing that fails to run — leaving a link that is expired in principle and working in practice.
     */
    case Expired = 'expired';

    public static function of(bool $isRevoked, ?DateTimeImmutable $expiresAt, DateTimeImmutable $now): self
    {
        if ($isRevoked) {
            return self::Revoked;
        }

        if ($expiresAt !== null && $expiresAt < $now) {
            return self::Expired;
        }

        return self::Active;
    }

    public function allowsAccess(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Revoked => 'Revoked',
            self::Expired => 'Expired',
        };
    }

    /**
     * What a visitor is told, which is never "not found".
     *
     * A 404 on a link a client was sent reads as a broken site; they ask for it again instead of learning that it
     * lapsed. The message says which it was without saying anything about the report behind it.
     */
    public function visitorMessage(): ?string
    {
        return match ($this) {
            self::Active => null,
            self::Revoked => 'This link has been turned off. Ask whoever sent it for a new one.',
            self::Expired => 'This link has expired. Ask whoever sent it for a new one.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
