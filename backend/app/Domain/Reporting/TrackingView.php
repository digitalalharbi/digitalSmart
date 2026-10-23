<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/** Which part of a client's first-party tracking a report section shows. Fixed shapes: a section picks a view, not a query. */
enum TrackingView: string
{
    case Links = 'links';
    case Forms = 'forms';
    case Funnel = 'funnel';
    case FollowUp = 'follow_up';
    case Health = 'health';

    public function label(): string
    {
        return match ($this) {
            self::Links => 'Link performance',
            self::Forms => 'Form performance',
            self::Funnel => 'Funnel',
            self::FollowUp => 'Lead follow-up',
            self::Health => 'Tracking health',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
