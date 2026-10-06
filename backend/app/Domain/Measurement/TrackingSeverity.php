<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

/**
 * How much a tracking problem matters.
 *
 * Three levels, because a list where everything is urgent is a list nobody reads. The test
 * for `Critical` is narrow on purpose: something a client is paying for is not being
 * measured at all. Everything else that merely *might* be wrong is a warning.
 */
enum TrackingSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Note',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
        };
    }

    /** Sort order for a list somebody works through: worst first. */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::Warning => 1,
            self::Info => 2,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
