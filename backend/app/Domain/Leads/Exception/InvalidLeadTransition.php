<?php

declare(strict_types=1);

namespace App\Domain\Leads\Exception;

use App\Domain\Shared\Exception\DomainException;

/** A pipeline move that would leave the record unable to explain itself. */
final class InvalidLeadTransition extends DomainException
{
    public function errorCode(): string
    {
        return 'invalid_lead_transition';
    }

    public static function disqualificationNeedsAReason(): self
    {
        /*
         * The message names the thing to do, because it reaches somebody working a pipeline. "Not a real
         * enquiry", "out of area" and "price" lead to three different conversations with a client paying for
         * leads, and a disqualified pile with no reasons is the least useful thing an agency can hand back.
         */
        return new self('Say why this lead was disqualified. A client paying for leads needs to know which reason it was.');
    }
}
