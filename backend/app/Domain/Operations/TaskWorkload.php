<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * What the board is carrying, as the figures a person running it is actually asked for.
 *
 * Pure arithmetic over counts, in the domain, because each of these has an obvious wrong version:
 *
 *  - **Overdue counts open work only.** Finished work that was late is history; counting it leaves a
 *    number nobody can ever clear.
 *  - **Undated work is published, not absorbed.** A board reporting a tidy pipeline while a third of its
 *    cards have no date is the failure this figure exists to prevent, so it has its own count beside the
 *    overdue one rather than disappearing into "later".
 *  - **Unassigned work is published too.** A workload chart that drew only the named people would show a
 *    comfortable team and hide the pile nobody owns.
 *  - **A completion rate needs a denominator that exists.** Null, never zero, when nothing has settled —
 *    `0%` and "nothing to measure" look identical on a card and mean opposite things.
 */
final readonly class TaskWorkload
{
    /**
     * The share of open work that must carry a date before lateness is reportable.
     *
     * Eighty percent, matching the judgement attribution coverage makes at seventy: a figure drawn from
     * most of the population describes it, and one drawn from half of it describes half of it. The exact
     * number is a product decision rather than a measurement, which is why it is a named constant with
     * the reasoning next to it rather than a literal in a condition.
     */
    public const DATED_SHARE_FLOOR = 80.0;

    /**
     * @param  array<string, int>  $byState  counts keyed by `TaskState` value, every state present
     * @param  array<string, int>  $byHorizon  counts keyed by `DueHorizon` value, every bucket present
     */
    public function __construct(
        public int $total,
        public int $open,
        public int $done,
        public int $cancelled,
        /** Open and past its date. See the note above. */
        public int $overdue,
        /** Open, with no date at all. The figure a board usually hides. */
        public int $undated,
        /** Open and nobody's. */
        public int $unassigned,
        /** Open and elevated priority — the work somebody will be asked about today. */
        public int $elevated,
        /** Open but waiting on a reviewer or an outside answer: work the board cannot push on its own. */
        public int $waiting,
        public array $byState,
        public array $byHorizon,
    ) {}

    /**
     * Of everything that has settled, how much was completed rather than abandoned.
     *
     * The denominator is settled work — done plus cancelled — not every task. Dividing by the whole board
     * would report a team that has just taken on a hundred new tasks as having got worse.
     *
     * Null when nothing has settled.
     */
    public function completionRate(): ?float
    {
        $settled = $this->done + $this->cancelled;

        return $settled === 0 ? null : round($this->done / $settled * 100, 1);
    }

    /**
     * What share of the open board has a date on it.
     *
     * The honest companion to the overdue count: a low figure here means the overdue count is not the
     * measure of lateness anybody thinks it is, because most of the work could not be late.
     *
     * Null when nothing is open.
     */
    public function datedShare(): ?float
    {
        return $this->open === 0 ? null : round(($this->open - $this->undated) / $this->open * 100, 1);
    }

    /**
     * Whether the overdue figure can be read as the amount of late work.
     *
     * False once a meaningful slice of the open board is undated, in which case the interface says so
     * beside the figure rather than leaving a reader to assume the rest is on time.
     */
    public function isLatenessReportable(): bool
    {
        $share = $this->datedShare();

        return $share !== null && $share >= self::DATED_SHARE_FLOOR;
    }
}
