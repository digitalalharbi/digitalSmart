<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * What a report is for, which decides how much of its definition is shown.
 *
 * Stored on the report rather than chosen at the screen, so the same report reads the same way in the app, in a
 * shared link and in every export. It changes content, not honesty: an executive report still carries its source,
 * period and caveats, and still never invents a figure to fill the room it saved.
 */
enum ReportIntent: string
{
    /** Everything the author defined, in full. The default: nothing is hidden unless somebody chose it. */
    case Detailed = 'detailed';

    /** The headline view: figures and rankings without the row-by-row tables, and short rankings. */
    case Executive = 'executive';

    public function label(): string
    {
        return match ($this) {
            self::Detailed => 'Detailed',
            self::Executive => 'Executive',
        };
    }

    /** Rankings never run longer than this in an executive report; null means no cap. */
    public function rowCap(): ?int
    {
        return match ($this) {
            self::Detailed => null,
            self::Executive => 5,
        };
    }

    /** A drill-down table repeats a ranking's rows with every metric at once, which is detail by definition. */
    public function shows(SectionKind $kind): bool
    {
        return ! ($this === self::Executive && $kind === SectionKind::Table);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
