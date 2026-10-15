<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

final class AccountNotBindable extends DomainException
{
    public static function because(string $accountName, string $status): self
    {
        return new self(sprintf(
            '"%s" cannot be linked to a project while its status is %s.',
            $accountName,
            strtolower($status),
        ));
    }

    /**
     * A client-granted connection serves that client and nobody else.
     *
     * The credentials were handed over for one client's accounts. Letting another client's project read
     * them would spend one client's access on a competitor's reporting — which is the leak the whole
     * isolation model exists to prevent, arriving through the one door that looked administrative.
     */
    public static function ownedByAnotherClient(string $accountName): self
    {
        return new self(sprintf(
            '"%s" belongs to a connection granted by a different client, so it can only serve that client\'s projects.',
            $accountName,
        ));
    }

    public function errorCode(): string
    {
        return 'account_not_bindable';
    }
}
