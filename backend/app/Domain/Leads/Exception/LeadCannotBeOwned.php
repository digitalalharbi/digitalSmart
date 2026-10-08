<?php

declare(strict_types=1);

namespace App\Domain\Leads\Exception;

use App\Domain\Shared\Exception\DomainException;

/** An owner who could not read the lead they were given. */
final class LeadCannotBeOwned extends DomainException
{
    public function errorCode(): string
    {
        return 'lead_cannot_be_owned';
    }

    /**
     * The refusal, with the thing to do about it.
     *
     * Not a permissions lecture: the person doing the assigning cannot grant access and should not be told to
     * try. What fixes this is assigning the colleague to one of the client's projects, which is where access to
     * a client comes from everywhere else in this product.
     */
    public static function byThatPerson(string $clientName): self
    {
        return new self(sprintf(
            'That person cannot open this lead, so putting it in their name would leave them owning a record '
            .'they never see — and the message telling them would be withheld for the same reason. Add them to '
            .'one of %s\'s projects first.',
            $clientName,
        ));
    }
}
