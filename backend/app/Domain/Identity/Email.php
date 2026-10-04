<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Shared\Exception\InvalidEmail;

final readonly class Email
{
    public string $value;

    public function __construct(string $value)
    {
        $normalised = strtolower(trim($value));

        if (filter_var($normalised, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidEmail::forValue($value);
        }

        $this->value = $normalised;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function domain(): string
    {
        $parts = explode('@', $this->value);

        return end($parts);
    }

    public function equals(?self $other): bool
    {
        return $other instanceof self && $other->value === $this->value;
    }
}
