<?php

declare(strict_types=1);

namespace App\Domain\Integration\Sync;

use DateTimeImmutable;

/**
 * Decides *which* accounts to sync now, in *what order*, and *how fast*.
 *
 * A pure function of its inputs — no clock, no database, no queue — because the policy is the part that
 * has to be argued with and tested exhaustively, and a scheduler that can only be exercised by running
 * workers is a scheduler nobody changes with confidence.
 *
 * Three things it is responsible for:
 *
 * **Adaptive cadence.** An account with campaigns running is read hourly; one with none, every six hours;
 * one whose projects are all finished, never, unless forced. Pulling a dormant account at the live rate
 * spends a provider's rate limit re-reading the same nothing.
 *
 * **Fairness.** Candidates are grouped by who the work is for and interleaved one at a time. A client with
 * fifty accounts and a client with two get their first jobs at the same position, so the small one is not
 * queued behind the large one's whole backlog.
 *
 * **Throttle.** Jobs for one provider are released in small bursts rather than all at once, because a
 * hundred accounts dispatched on the same tick are limited only by worker count — and providers limit by
 * app, not by account.
 */
final class SyncPlanner
{
    /** A live account whose last success is older than this many cadences is not slow, it is stuck. */
    public const int STALE_AFTER_CADENCES = 3;

    /** Jobs per provider released together before the next burst is held back. */
    public const int BURST = 5;

    public const int BURST_SPACING_SECONDS = 10;

    /**
     * @param  list<SyncCandidate>  $candidates
     * @param  bool  $backfill  An operator asked for a window: everything runs, at the lowest priority.
     * @param  bool  $force  Ignore freshness and archive state.
     * @return list<PlannedSync>
     */
    public function plan(array $candidates, DateTimeImmutable $now, bool $backfill = false, bool $force = false): array
    {
        $classified = [];

        foreach ($candidates as $candidate) {
            $class = $this->classify($candidate, $now, $backfill, $force);

            if ($class !== null) {
                $classified[] = [$candidate, $class];
            }
        }

        return $this->throttle($this->interleave($classified));
    }

    private function classify(
        SyncCandidate $candidate,
        DateTimeImmutable $now,
        bool $backfill,
        bool $force,
    ): ?SyncClass {
        if ($backfill) {
            return SyncClass::Backfill;
        }

        if (! $candidate->hasLiveProject && ! $force) {
            return null;
        }

        $active = $candidate->activeCampaigns > 0;

        if (! $candidate->lastSuccessAt instanceof DateTimeImmutable) {
            /* Never read. A brand-new account should not wait out a dormant cadence for its first figures. */
            return $active || $candidate->hasLiveProject ? SyncClass::Live : SyncClass::Dormant;
        }

        $cadence = ($active ? SyncClass::Live : SyncClass::Dormant)->cadenceMinutes() ?? 60;
        $ageMinutes = (int) floor(($now->getTimestamp() - $candidate->lastSuccessAt->getTimestamp()) / 60);

        if ($active && $ageMinutes > $cadence * self::STALE_AFTER_CADENCES) {
            return SyncClass::Recovery;
        }

        if ($ageMinutes < $cadence && ! $force) {
            return null;
        }

        return $active ? SyncClass::Live : SyncClass::Dormant;
    }

    /**
     * Round-robin across whoever the work is for, best class first within each.
     *
     * @param  list<array{0: SyncCandidate, 1: SyncClass}>  $classified
     * @return list<array{0: SyncCandidate, 1: SyncClass}>
     */
    private function interleave(array $classified): array
    {
        $groups = [];

        foreach ($classified as $entry) {
            $groups[$entry[0]->fairnessKey][] = $entry;
        }

        foreach ($groups as &$group) {
            usort($group, static function (array $a, array $b): int {
                /* Class first; within a class the account waiting longest goes first. A never-read one is longest. */
                return [$a[1]->priority(), $a[0]->lastSuccessAt?->getTimestamp() ?? 0, $a[0]->externalAccountId]
                    <=> [$b[1]->priority(), $b[0]->lastSuccessAt?->getTimestamp() ?? 0, $b[0]->externalAccountId];
            });
        }
        unset($group);

        /* The group with the most urgent work leads each round, so a recovery is never behind a routine pass. */
        uasort($groups, static fn (array $a, array $b): int => [$a[0][1]->priority(), $a[0][0]->fairnessKey]
            <=> [$b[0][1]->priority(), $b[0][0]->fairnessKey]);

        $ordered = [];
        $groups = array_values($groups);

        for ($round = 0; ; $round++) {
            $any = false;

            foreach ($groups as $group) {
                if (isset($group[$round])) {
                    $ordered[] = $group[$round];
                    $any = true;
                }
            }

            if (! $any) {
                break;
            }
        }

        /* Recovery jumps the whole line: it sits in its own queue, but within one worker it should go first too. */
        usort($ordered, static fn (array $a, array $b): int => $a[1]->priority() <=> $b[1]->priority());

        return $ordered;
    }

    /**
     * @param  list<array{0: SyncCandidate, 1: SyncClass}>  $ordered
     * @return list<PlannedSync>
     */
    private function throttle(array $ordered): array
    {
        $perProvider = [];
        $planned = [];

        foreach ($ordered as [$candidate, $class]) {
            $position = $perProvider[$candidate->provider->value] ?? 0;
            $perProvider[$candidate->provider->value] = $position + 1;

            $planned[] = new PlannedSync(
                $candidate,
                $class,
                intdiv($position, self::BURST) * self::BURST_SPACING_SECONDS,
            );
        }

        return $planned;
    }
}
