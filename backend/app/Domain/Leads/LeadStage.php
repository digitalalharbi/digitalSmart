<?php

declare(strict_types=1);

namespace App\Domain\Leads;

/**
 * Where a lead stands in a pipeline.
 *
 * Deliberately short. Every agency wants its own stages, and a product that let each client invent twelve
 * of them cannot answer "how many of last month's leads were any good" across two clients — which is the
 * one question an agency's own reporting needs. Custom labels belong on top of these, not instead of them.
 */
enum LeadStage: string
{
    /** Arrived, nobody has tried to reach them yet. */
    case New = 'new';

    /** Somebody tried. Reaching out is not reaching. */
    case Contacted = 'contacted';

    /** Reached, and worth pursuing. A human decided this. */
    case Qualified = 'qualified';

    /** Reached, and not worth pursuing. Also a human decision, and worth recording as one. */
    case Disqualified = 'disqualified';

    case Won = 'won';

    case Lost = 'lost';

    /**
     * Whether this lead is finished.
     *
     * The denominator of a win rate, and the reason it is a method rather than a comment: an open lead is
     * not a loss, and dividing wins by *all* leads understates a pipeline that is simply young. That
     * arithmetic has sunk more agency reports than any other single mistake in this area.
     */
    public function isClosed(): bool
    {
        return $this === self::Won || $this === self::Lost || $this === self::Disqualified;
    }

    /** Whether somebody has actually made contact — as opposed to having tried. */
    public function isReached(): bool
    {
        return $this !== self::New && $this !== self::Contacted;
    }

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Qualified => 'Qualified',
            self::Disqualified => 'Disqualified',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The order a pipeline reads in, for a board or a funnel.
     *
     * @return list<self>
     */
    public static function pipelineOrder(): array
    {
        return [self::New, self::Contacted, self::Qualified, self::Won, self::Lost, self::Disqualified];
    }
}
