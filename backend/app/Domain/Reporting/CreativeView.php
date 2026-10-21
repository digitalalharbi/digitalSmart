<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * Which part of the creative picture one creative section shows.
 *
 * A creative section is a single block, like every other section; a report that wants the top creatives and the ones
 * to refresh adds two. Each view is a read of the same creative layer the Creatives page uses, so a client sees the
 * figures the agency saw.
 */
enum CreativeView: string
{
    /** The creatives the layer rates best, with the picture. */
    case Top = 'top';

    /** The largest creatives by spend, every figure side by side. */
    case Performance = 'performance';

    /** Where the money went by format. */
    case FormatMix = 'format_mix';

    /** The four largest creatives, set against each other. */
    case Comparison = 'comparison';

    /** Creatives wearing out, or weak enough to replace. */
    case Refresh = 'refresh';

    public function label(): string
    {
        return match ($this) {
            self::Top => 'Top creatives',
            self::Performance => 'Creative performance',
            self::FormatMix => 'Format mix',
            self::Comparison => 'Creative comparison',
            self::Refresh => 'Creatives to refresh',
        };
    }

    /** How many creatives the view shows when the report does not say. */
    public function defaultLimit(): int
    {
        return match ($this) {
            self::Top => 6,
            self::Performance => 10,
            self::FormatMix => 100,
            self::Comparison => 4,
            self::Refresh => 6,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
