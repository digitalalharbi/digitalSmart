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

    public function errorCode(): string
    {
        return 'account_not_bindable';
    }
}
