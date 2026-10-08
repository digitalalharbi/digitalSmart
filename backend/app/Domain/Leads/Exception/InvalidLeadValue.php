<?php

declare(strict_types=1);

namespace App\Domain\Leads\Exception;

use App\Domain\Shared\Exception\DomainException;

/** A figure that would not survive being quoted to a client. */
final class InvalidLeadValue extends DomainException
{
    public function errorCode(): string
    {
        return 'invalid_lead_value';
    }

    public static function negative(): self
    {
        return new self('A lead cannot be worth less than nothing. Clear the value instead, which means nobody has said.');
    }

    public static function needsACurrency(): self
    {
        return new self('Say which currency this is in. A value with no currency is a number with no unit, and this workspace has clients in more than one.');
    }

    /**
     * The same position the stage takes, and for the same reason.
     *
     * A CRM holds the deal's amount. A figure typed here would be overwritten by the next sync without anybody
     * being told — and in between, two different numbers would have been quoted to the same client.
     */
    public static function ownedByTheCrm(string $sourceDetail): self
    {
        return new self(sprintf(
            'This lead\'s value is held by %s, where the deal lives. Change the amount there and it arrives '
            .'here on the next sync; typing it here would be undone by that sync.',
            $sourceDetail === '' ? 'the CRM' : $sourceDetail,
        ));
    }
}
