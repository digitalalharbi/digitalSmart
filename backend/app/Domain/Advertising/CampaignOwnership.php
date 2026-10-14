<?php

declare(strict_types=1);

namespace App\Domain\Advertising;

/**
 * How a campaign's project was decided.
 *
 * Four states, and the product shows all four rather than collapsing them into "project / no project". The
 * difference between them is the difference between a settled fact and an unanswered question:
 *
 *  - **Assigned** — somebody chose. Survives re-sync; survives a binding being added.
 *  - **Implied** — the account serves exactly one project, so there is nothing to choose.
 *  - **Ambiguous** — the account serves several projects and nobody has chosen. The campaign is real, its
 *    spend is real, and no project may claim it. This is the state the column exists for.
 *  - **Unbound** — the account serves no project at all. Discovered, never bound, so nothing reads it.
 *
 * Ambiguous and unbound both mean "no project", and treating them alike would send an operator to the wrong
 * screen: one is fixed by assigning the campaign, the other by binding the account.
 */
enum CampaignOwnership: string
{
    case Assigned = 'assigned';
    case Implied = 'implied';
    case Ambiguous = 'ambiguous';
    case Unbound = 'unbound';

    public function label(): string
    {
        return match ($this) {
            self::Assigned => 'Assigned',
            self::Implied => 'From its account',
            self::Ambiguous => 'Needs assigning',
            self::Unbound => 'Account not bound',
        };
    }

    /** Whether somebody has to act. Both no-project states do; neither settled state does. */
    public function needsAttention(): bool
    {
        return $this === self::Ambiguous || $this === self::Unbound;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
