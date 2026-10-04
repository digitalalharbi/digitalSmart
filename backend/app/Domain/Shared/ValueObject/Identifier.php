<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

use App\Domain\Shared\Exception\InvalidIdentifier;

/**
 * Base for typed entity identifiers.
 *
 * Subclasses exist so a ClientId can never be passed where a ProjectId is
 * expected — a mistake a bare string would hide until it corrupted data.
 */
abstract readonly class Identifier
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    final public function __construct(public string $value)
    {
        if (preg_match(self::UUID_PATTERN, $this->value) !== 1) {
            throw InvalidIdentifier::forValue(static::class, $this->value);
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function fromString(string $value): static
    {
        return new static($value);
    }

    public function equals(?self $other): bool
    {
        return $other instanceof static && $other->value === $this->value;
    }
}
