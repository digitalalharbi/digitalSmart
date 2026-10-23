<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Cost;

/**
 * How the spend beside a link's figures relates to the link.
 *
 * Direct and allocated are different claims and are never shown as one: a direct figure is the spend of the very entity
 * the link is bound to, with nothing else competing for it; an allocated figure is a share of that spend under a rule the
 * reader can see. Unavailable is the default, and says why.
 */
enum SpendBasis: string
{
    case Direct = 'DIRECT';
    case Allocated = 'ALLOCATED';
    case Unavailable = 'UNAVAILABLE';

    public function hasSpend(): bool
    {
        return $this !== self::Unavailable;
    }
}
