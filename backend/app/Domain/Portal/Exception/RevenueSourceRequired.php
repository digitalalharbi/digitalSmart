<?php

declare(strict_types=1);

namespace App\Domain\Portal\Exception;

use DomainException;

/**
 * A revenue figure was asked for without naming who counted it.
 *
 * Its own exception, raised by the use case **after** the portal's access check, so that a reader who may not open
 * the surface is told it is not there rather than told which parameter they left out. A refusal naming
 * `revenue_source` is a statement that the surface exists and would answer, which is not a sentence this product
 * says to somebody it has just refused.
 */
final class RevenueSourceRequired extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'A revenue source is required: these figures mean different things depending on who is counting them.',
        );
    }
}
