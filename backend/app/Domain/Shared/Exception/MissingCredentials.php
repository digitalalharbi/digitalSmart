<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

final class MissingCredentials extends DomainException
{
    /** @param list<string> $missingKeys */
    public static function for(string $provider, array $missingKeys): self
    {
        return new self(sprintf(
            'The %s connection is missing required credentials: %s.',
            $provider,
            implode(', ', $missingKeys),
        ));
    }

    public function errorCode(): string
    {
        return 'missing_credentials';
    }
}
