<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use DateTimeImmutable;
use DateTimeZone;

/** When a rule is evaluated. The routine runs every fifteen minutes; each rule decides whether it is due. */
enum RuleSchedule: string
{
    /** Every run of the routine — about every fifteen minutes. */
    case Continuous = 'continuous';
    case Hourly = 'hourly';
    /** Once a day, at a time in the rule's own time zone. */
    case Daily = 'daily';

    public function isDue(?DateTimeImmutable $lastRun, DateTimeImmutable $now, ?string $time, string $timezone): bool
    {
        if ($this === self::Continuous || $lastRun === null && $this !== self::Daily) {
            return true;
        }

        if ($this === self::Hourly) {
            return $lastRun === null || $now->getTimestamp() - $lastRun->getTimestamp() >= 55 * 60;
        }

        $zone = new DateTimeZone($timezone);
        $local = $now->setTimezone($zone);
        [$hour, $minute] = array_map('intval', explode(':', $time ?? '08:00') + [1 => '0']);
        $slot = $local->setTime($hour, $minute);

        return $local >= $slot && ($lastRun === null || $lastRun->setTimezone($zone) < $slot);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
