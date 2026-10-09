<?php

declare(strict_types=1);

namespace App\Domain\Operations\Exception;

use App\Domain\Shared\Exception\DomainException;

/** Work put in the name of somebody who could not open it. */
final class CannotBeAssigned extends DomainException
{
    public function errorCode(): string
    {
        return 'cannot_be_assigned';
    }

    /**
     * The refusal, with the thing to do about it.
     *
     * Not a permissions lecture: whoever is assigning cannot grant access and should not be told to try. What
     * fixes this is adding the colleague to one of the client's projects, which is where access to a client comes
     * from everywhere else in this product.
     */
    public static function toThatPerson(string $clientName): self
    {
        return new self(sprintf(
            'That person cannot open this, so putting it in their name would leave them owning work they never '
            .'see — and the message telling them would be withheld for the same reason. Add them to one of %s '
            .'first.',
            /*
             * Two sentences rather than one with a possessive glued on. The unnamed case happens for work that
             * belongs to no client, and "one of the client's's projects" is what a format string does when
             * nobody checks the fallback reads as English.
             */
            $clientName === '' ? 'the projects it covers' : $clientName.'\'s projects',
        ));
    }
}
