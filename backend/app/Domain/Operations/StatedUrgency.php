<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * How urgent the **client** said it was.
 *
 * Deliberately its own enum rather than `TaskPriority`, and the distinction is the point: this is a claim the
 * client made, and the agency's own priority is a judgement it makes afterwards. Storing both in one field would
 * mean a client could set an agency's priorities by typing, and a board where every card is urgent is a board
 * nobody triages.
 *
 * So nothing in this product presents a stated urgency as a priority. It is shown as what the client said,
 * beside whatever the agency decided, and the two are allowed to differ.
 */
enum StatedUrgency: string
{
    case WheneverYouCan = 'whenever';

    case ThisMonth = 'this_month';

    case ThisWeek = 'this_week';

    /** The client says it is urgent. Recorded as their word, never as the board's priority. */
    case Urgent = 'urgent';

    public function rank(): int
    {
        return match ($this) {
            self::WheneverYouCan => 0,
            self::ThisMonth => 1,
            self::ThisWeek => 2,
            self::Urgent => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::WheneverYouCan => 'Whenever you can',
            self::ThisMonth => 'This month',
            self::ThisWeek => 'This week',
            self::Urgent => 'Urgent',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<self> */
    public static function ascending(): array
    {
        $cases = self::cases();

        usort($cases, static fn (self $a, self $b): int => $a->rank() <=> $b->rank());

        return $cases;
    }
}
