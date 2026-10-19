<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Blueprint;

/** How much of one blueprint field a platform can take, said plainly before anything is made. */
enum FieldSupport: string
{
    case Supported = 'supported';
    /** Taken, but not exactly as written — the note says what changes. */
    case Partial = 'partial';
    /** Not taken at all on this platform. */
    case Unsupported = 'unsupported';
}
