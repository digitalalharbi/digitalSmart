<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * Who a report is for.
 *
 * The distinction is not cosmetic: a client-safe report is a **different document**, not the internal one with a
 * banner on it. It omits the agency's own commentary, and the sharing endpoint builds it from a read that never
 * selects that commentary — the same rule client requests follow, for the same reason.
 */
enum ReportAudience: string
{
    /** The agency's own copy. Carries internal notes. */
    case Internal = 'internal';

    /** Written to be read by the client. No internal notes, ever. */
    case ClientSafe = 'client_safe';

    public function allowsInternalNotes(): bool
    {
        return $this === self::Internal;
    }

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Internal',
            self::ClientSafe => 'Safe to send to the client',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
