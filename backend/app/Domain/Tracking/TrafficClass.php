<?php

declare(strict_types=1);

namespace App\Domain\Tracking;

/**
 * What an event is believed to be. Never deleted, only marked: a heuristic that is sometimes wrong must not be
 * allowed to destroy the evidence of its own mistake.
 */
enum TrafficClass: string
{
    case Valid = 'valid';
    case SuspectedBot = 'suspected_bot';
    case Duplicate = 'duplicate';
    case Test = 'test';

    /** Whether analytics counts it by default. A reader can ask for the rest. */
    public function countsByDefault(): bool
    {
        return $this === self::Valid;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
