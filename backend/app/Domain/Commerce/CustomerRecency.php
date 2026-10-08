<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

/**
 * Whether a customer was new to this store in the period, or had bought before.
 *
 * **No window, and that is the point.** The roadmap recorded this gap as needing a product decision —
 * *"returning within what window?"* — and the window only appears if you try to answer a different question.
 * "Returning" here is not "active recently": it is a statement about the data, and the data answers it exactly.
 *
 * A customer's first order either falls inside the period or before it. Nothing to choose.
 *
 * `Unknown` is the case that makes the metric defensible. A store's history in this product starts when the
 * integration was connected, so a customer whose first recorded order is at the very beginning of what was synced
 * may have been buying for years. Counting them as **new** would invent new customers on day one of every
 * integration — the figure would look spectacular and be worthless. Counting them as **returning** would invent a
 * history nobody read. So they are counted separately and the surface says how many, which is a number that
 * shrinks on its own as the store accumulates real history.
 *
 * "Active recently" — reactivated, lapsed, win-back — is a different metric with a real window in it, and a real
 * product decision behind the window. It is deliberately not built; see `docs/ECOMMERCE.md`.
 */
enum CustomerRecency: string
{
    /** Their first recorded order is inside the period. */
    case New = 'new';

    /** Their first recorded order is before the period. */
    case Returning = 'returning';

    /**
     * Their first recorded order is at the edge of what was ever synced, so this product cannot tell.
     *
     * Not an error and not a gap in the data: it is the honest answer about a customer whose history began before
     * this product was watching.
     */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New to this store',
            self::Returning => 'Bought before',
            self::Unknown => 'Started before this store was connected',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
