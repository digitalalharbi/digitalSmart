<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Funnel;

/**
 * The raw material of a funnel: the visits in a window and what became of each, as timestamps.
 *
 * Everything is keyed by session, because a visit is where the journey starts. A session that produced a lead carries that
 * lead's own timestamps, so "time from the form to the first contact" is measured on the same journey it counts.
 */
final readonly class FunnelCohort
{
    /**
     * @param  list<array{started: int, landing: ?int, form_start: ?int, submit: ?int, lead: ?string}>  $sessions
     * @param  int  $submissions  submissions made from these sessions
     * @param  array<string, array{received: int, contacted: ?int, qualified: ?int, appointment: ?int, opportunity: ?int, won: ?int}>  $leads  by lead id
     */
    public function __construct(
        public array $sessions,
        public int $submissions,
        public array $leads,
        public bool $truncated = false,
        public int $excludedSessions = 0,
    ) {}
}
