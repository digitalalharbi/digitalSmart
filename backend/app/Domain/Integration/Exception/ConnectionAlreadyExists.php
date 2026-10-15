<?php

declare(strict_types=1);

namespace App\Domain\Integration\Exception;

use App\Domain\Shared\Exception\DomainException;

final class ConnectionAlreadyExists extends DomainException
{
    public static function forClient(string $providerLabel): self
    {
        return new self(sprintf(
            'This client has already granted a %s connection. Reconnect it instead of adding a second.',
            $providerLabel,
        ));
    }

    public function errorCode(): string
    {
        return 'connection_already_exists';
    }
}
