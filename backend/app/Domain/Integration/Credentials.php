<?php

declare(strict_types=1);

namespace App\Domain\Integration;

use App\Domain\Shared\Exception\MissingCredentials;
use SensitiveParameter;

/**
 * A provider's secrets.
 *
 * Never logged, never serialised into an API resource, never put in a URL. The
 * only way out of this object is `toArray()`, which exists for the encrypting
 * repository — everything else can ask `isComplete()` or `describe()`.
 */
final readonly class Credentials
{
    /** @var array<string, string> */
    private array $values;

    /** @param array<string, string> $values */
    public function __construct(#[SensitiveParameter] array $values = [])
    {
        $clean = [];

        foreach ($values as $key => $value) {
            $trimmed = trim($value);

            if ($trimmed !== '') {
                $clean[$key] = $trimmed;
            }
        }

        $this->values = $clean;
    }

    /** Guard against a credential ending up in a log line or a stack trace. */
    public function __debugInfo(): array
    {
        return ['values' => '[redacted]', 'keys' => $this->describe()];
    }

    public static function empty(): self
    {
        return new self;
    }

    /** @param list<string> $requiredKeys */
    public function isComplete(array $requiredKeys): bool
    {
        foreach ($requiredKeys as $key) {
            if (! isset($this->values[$key])) {
                return false;
            }
        }

        return $requiredKeys !== [];
    }

    /**
     * @param  list<string>  $requiredKeys
     * @return list<string> the keys that are still missing
     */
    public function missing(array $requiredKeys): array
    {
        return array_values(array_filter(
            $requiredKeys,
            fn (string $key): bool => ! isset($this->values[$key]),
        ));
    }

    /** @param list<string> $requiredKeys */
    public function assertComplete(Provider $provider, array $requiredKeys): void
    {
        $missing = $this->missing($requiredKeys);

        if ($missing !== []) {
            throw MissingCredentials::for($provider->value, $missing);
        }
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    /**
     * Raw values. Only the encrypting repository calls this.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Which keys are present — never their values.
     *
     * @return list<string>
     */
    public function describe(): array
    {
        return array_keys($this->values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }
}
