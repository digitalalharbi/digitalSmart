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

    /**
     * A lead the CRM owns cannot be moved here.
     *
     * The refusal rather than a silent overwrite, and the reason matters more than the rule: two systems both
     * claiming to own a deal's stage is how a sales team loses a day's work to a sync. Whichever one wrote last
     * wins, nobody can tell which that was, and the figure a client is shown depends on the order of two jobs.
     *
     * So the CRM owns what the CRM owns, this product reads it, and the surface says so where the control would
     * have been — rather than offering a move that the next sync would quietly undo.
     */
    public static function ownedByTheCrm(string $sourceDetail): self
    {
        return new self(sprintf(
            'This lead\'s stage is owned by %s, where it came from. Move it there and it will arrive here on '
            .'the next sync; moving it here would be undone by that sync without anybody being told.',
            $sourceDetail === '' ? 'the CRM' : $sourceDetail,
        ));
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
