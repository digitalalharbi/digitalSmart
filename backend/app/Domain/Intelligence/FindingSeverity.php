<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * How much a finding matters.
 *
 * Four levels, and the lowest one is the one that keeps the list honest. **`Context`** is a fact worth stating
 * that is not a problem — "spend is flat and so is everything else" — and a list that omitted it would imply that
 * silence means health. A reader who sees three warnings and nothing else cannot tell whether the analysers looked
 * at anything beyond the three.
 */
enum FindingSeverity: string
{
    /** Something is wrong now and money is going somewhere nobody chose. */
    case Serious = 'serious';

    /** A trend worth a decision this week. */
    case Warning = 'warning';

    /** Worth knowing, needing no decision. */
    case Context = 'context';

    /**
     * Something the analysers could not say, and why.
     *
     * Its own level rather than an absence. "Not enough delivery to compare" is the honest answer to a question
     * about a campaign that spent eleven riyals, and dropping it would leave a reader believing the question had
     * been answered.
     */
    case Insufficient = 'insufficient';

    public function label(): string
    {
        return match ($this) {
            self::Serious => 'Needs attention now',
            self::Warning => 'Worth a decision',
            self::Context => 'Worth knowing',
            self::Insufficient => 'Not enough data to say',
        };
    }

    /** Ordering weight, highest first. Used to sort a findings list rather than to compare two findings. */
    public function weight(): int
    {
        return match ($this) {
            self::Serious => 40,
            self::Warning => 30,
            self::Context => 20,
            self::Insufficient => 10,
        };
    }

    /** Whether this is a problem rather than a statement. Drives the one count a reader reads first. */
    public function needsAttention(): bool
    {
        return $this === self::Serious || $this === self::Warning;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
