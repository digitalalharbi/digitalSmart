<?php

declare(strict_types=1);

namespace App\Domain\Leads;

/**
 * The rates an agency is asked for, with denominators that exist.
 *
 * Pure arithmetic over counts, in the domain, because every one of these rates has an obvious wrong version
 * and the wrong versions are what agencies report:
 *
 *  - **A win rate over all leads** understates a young pipeline, because an open lead is not a loss. The
 *    denominator is *closed* leads.
 *  - **A qualification rate over reached leads** is the honest one. Dividing by every lead that arrived
 *    counts the ones nobody has phoned yet as failures.
 *  - **A rate with no denominator is not a rate.** Every method here returns null rather than zero when
 *    there is nothing to divide by, because `0%` and "nothing to measure" look identical on a card and
 *    mean opposite things.
 *
 * There is deliberately **no lead-to-click or lead-to-session rate here**, because this object has no
 * honest denominator for one: sessions come from an analytics property, leads from a CRM or a form, and
 * dividing one by the other silently assumes every session had the chance to convert. That comparison
 * belongs where both numbers' sources are named, and it is Phase 17's problem.
 */
final readonly class LeadFunnel
{
    public function __construct(
        public int $total,
        public int $contacted,
        public int $reached,
        public int $qualified,
        public int $disqualified,
        public int $won,
        public int $lost,
        /** Still in play. Not a loss, and the reason the win rate's denominator is not `total`. */
        public int $open,
        public int $wonValueMinor = 0,
        public int $openValueMinor = 0,
    ) {}

    /** Closed one way or another: won, lost or disqualified. */
    public function closed(): int
    {
        return $this->won + $this->lost + $this->disqualified;
    }

    /**
     * Wins as a share of closed leads, 0–100, or null.
     *
     * The denominator excludes open leads. A pipeline a fortnight old is mostly open, and counting those as
     * losses would make a good month look like a bad one.
     */
    public function winRate(): ?float
    {
        return $this->rate($this->won, $this->closed());
    }

    /**
     * Qualified as a share of leads somebody actually reached, or null.
     *
     * Reached rather than total, because a lead nobody has phoned yet has not failed to qualify.
     */
    public function qualificationRate(): ?float
    {
        return $this->rate($this->qualified, $this->reached);
    }

    /**
     * How many of the leads that arrived somebody has tried to contact, or null.
     *
     * The operational figure: a client paying for leads nobody rings is paying for nothing, and this is the
     * number that says so before the win rate does.
     */
    public function contactRate(): ?float
    {
        return $this->rate($this->contacted + $this->reached, $this->total);
    }

    /** Average value of a won lead, or null where none were won or none carried a value. */
    public function averageWonValueMinor(): ?int
    {
        return $this->won > 0 && $this->wonValueMinor > 0
            ? intdiv($this->wonValueMinor, $this->won)
            : null;
    }

    /**
     * A percentage, or null when the denominator is zero.
     *
     * Null rather than zero, every time. `0%` on a card invites a comparison with last month; "no data"
     * does not.
     */
    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : null;
    }
}
