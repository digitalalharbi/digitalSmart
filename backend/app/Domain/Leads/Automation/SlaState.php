<?php

declare(strict_types=1);

namespace App\Domain\Leads\Automation;

/** The four states a lead is shown in, plus the one between "nothing yet" and "reached". */
enum SlaState: string
{
    case Within = 'within';
    case Approaching = 'approaching';
    case Overdue = 'overdue';
    /** Somebody tried and has not got through. */
    case Attempted = 'attempted';
    case Contacted = 'contacted';

    public function isWaiting(): bool
    {
        return $this === self::Within || $this === self::Approaching || $this === self::Overdue;
    }
}
