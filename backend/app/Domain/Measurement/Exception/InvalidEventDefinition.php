<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Exception;

use App\Domain\Shared\Exception\DomainException;

/**
 * A conversion definition that could not mean what it says.
 *
 * These messages reach somebody setting up measurement for a client, so each names the thing
 * to change rather than the rule that was broken.
 */
final class InvalidEventDefinition extends DomainException
{
    public function errorCode(): string
    {
        return 'invalid_event_definition';
    }

    public static function primaryMustBeAConversion(): self
    {
        return new self('Only a conversion can be the primary one. Mark it as a conversion first, or choose another.');
    }

    public static function needsAtLeastOneMapping(string $name): self
    {
        return new self(sprintf(
            '"%s" has no event names mapped to it, so nothing would ever count towards it.',
            $name,
        ));
    }

    public static function duplicateRawName(string $rawName): self
    {
        return new self(sprintf(
            '"%s" is already mapped to another conversion on this project. Every mapping would count the same event.',
            $rawName,
        ));
    }

    public static function nameAlreadyUsed(string $name): self
    {
        return new self(sprintf('This project already has a conversion called "%s".', $name));
    }
}
