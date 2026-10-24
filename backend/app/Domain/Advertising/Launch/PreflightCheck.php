<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Launch;

/** One thing checked before anything is created, what was found, and what fixes it. */
final readonly class PreflightCheck
{
    public function __construct(
        public string $key,
        public PreflightState $state,
        public string $message,
        /** What changes the state. Null only when the check is ready. */
        public ?string $fix = null,
    ) {}

    public static function ready(string $key, string $message): self
    {
        return new self($key, PreflightState::Ready, $message);
    }

    public static function of(string $key, PreflightState $state, string $message, string $fix): self
    {
        return new self($key, $state, $message, $fix);
    }
}
