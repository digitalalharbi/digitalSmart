<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * Something the assistant is expected to do and does not do yet, with the reason.
 *
 * Published rather than omitted, and published rather than shipped as an empty tab. A mode that appeared in the
 * interface and produced nothing would be a placeholder wearing a feature's clothes; a mode that is absent with no
 * explanation gets asked about once a quarter forever. The same argument the portal makes about the surfaces it
 * will not expose, applied to work that is simply not finished.
 */
enum DeferredMode: string
{
    case Plan = 'plan';

    public function label(): string
    {
        return match ($this) {
            self::Plan => 'Plan',
        };
    }

    public function because(): string
    {
        return match ($this) {
            self::Plan => 'A media plan needs the objective, the budget and the flight dates somebody has agreed '
                .'with the client, and this product holds none of those at the point the assistant runs. Drafting '
                .'one from performance alone would produce a plan shaped entirely by what was easiest to measure '
                .'last month. The media planner is where a plan is built, and it starts from the brief.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
