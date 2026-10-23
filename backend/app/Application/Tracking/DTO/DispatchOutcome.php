<?php

declare(strict_types=1);

namespace App\Application\Tracking\DTO;

/**
 * What came of an attempt, in the only four ways it can.
 *
 * `unknown` is its own outcome: a request that timed out may or may not have been applied, and treating it as a failure to
 * retry is how one conversion becomes two.
 */
final readonly class DispatchOutcome
{
    private function __construct(
        public string $kind,
        public ?string $providerRef = null,
        public ?bool $received = null,
        public ?bool $deduplicated = null,
        public ?string $category = null,
    ) {}

    public static function sent(?string $ref, ?bool $received = null, ?bool $deduplicated = null): self
    {
        return new self('sent', $ref, $received, $deduplicated);
    }

    /** The provider understood and declined; it will decline again. */
    public static function rejected(string $category): self
    {
        return new self('rejected', category: $category);
    }

    /** Rate limit, server error, no connection yet: safe to try again later with the same event id. */
    public static function transient(string $category): self
    {
        return new self('transient', category: $category);
    }

    public static function unknown(): self
    {
        return new self('unknown');
    }
}
