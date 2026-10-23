<?php

declare(strict_types=1);

namespace App\Domain\Leads\Automation;

use DateTimeImmutable;

/**
 * Where a lead stands against the response time its project promised.
 *
 * Two clocks, kept apart: the first **attempt** (somebody moved the lead out of "new") and the first **contact**
 * (somebody got through). The promise is about the attempt — it is the one a person controls — so the state is judged on it,
 * and `contacted` is reported on its own once reached. An attempt is never counted as contact.
 *
 * Elapsed time is absolute, so a promise made at 23:50 in Riyadh is judged by the same number of minutes whether or not a
 * date line, a time zone or a daylight-saving change sits between the two moments; the time zone only decides how the
 * deadline is written down for people.
 */
final readonly class LeadSla
{
    /** The share of the response time after which a lead with no attempt is called out as approaching it. */
    public const float APPROACHING_AT = 0.75;

    public const int MIN_MINUTES = 1;

    public const int MAX_MINUTES = 10_080;

    private function __construct(
        public SlaState $state,
        public DateTimeImmutable $dueAt,
        /** Seconds from arrival to the first attempt, when there was one. */
        public ?int $secondsToAttempt,
        /** Whether the first attempt, or the contact if there was no attempt record, came inside the promise. */
        public ?bool $met,
    ) {}

    public static function judge(
        DateTimeImmutable $receivedAt,
        ?DateTimeImmutable $firstAttemptAt,
        ?DateTimeImmutable $firstContactedAt,
        int $slaMinutes,
        DateTimeImmutable $now,
    ): self {
        $due = $receivedAt->modify(sprintf('+%d minutes', $slaMinutes));
        $first = self::earliest($firstAttemptAt, $firstContactedAt);
        $seconds = $first === null ? null : max(0, $first->getTimestamp() - $receivedAt->getTimestamp());

        if ($firstContactedAt !== null) {
            return new self(SlaState::Contacted, $due, $seconds, $first <= $due);
        }

        if ($firstAttemptAt !== null) {
            return new self(SlaState::Attempted, $due, $seconds, $firstAttemptAt <= $due);
        }

        $elapsed = $now->getTimestamp() - $receivedAt->getTimestamp();
        $limit = $slaMinutes * 60;

        $state = match (true) {
            $elapsed >= $limit => SlaState::Overdue,
            $elapsed >= (int) ceil($limit * self::APPROACHING_AT) => SlaState::Approaching,
            default => SlaState::Within,
        };

        return new self($state, $due, null, $state === SlaState::Overdue ? false : null);
    }

    private static function earliest(?DateTimeImmutable $a, ?DateTimeImmutable $b): ?DateTimeImmutable
    {
        return match (true) {
            $a === null => $b,
            $b === null => $a,
            default => min($a, $b),
        };
    }
}
