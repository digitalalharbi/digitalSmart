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
    /**
     * The credential value that opts a connection into fixtures.
     *
     * Deliberately explicit and ugly: nobody types it by accident, and it cannot
     * be mistaken for a real secret. It lives in the domain because "is this
     * connection a sandbox" decides how every number it produces is labelled,
     * which is a business rule, not an adapter detail.
     */
    public const SANDBOX_TOKEN = 'sandbox';

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

    /**
     * Whether every required credential is the sandbox token.
     *
     * All of them, not any: a connection half-filled with a real secret is a
     * configuration mistake, and treating it as a sandbox would hide that.
     *
     * @param  list<string>  $requiredKeys
     */
    public function isSandbox(array $requiredKeys): bool
    {
        if ($requiredKeys === []) {
            return false;
        }

        foreach ($requiredKeys as $key) {
            if (($this->values[$key] ?? null) !== self::SANDBOX_TOKEN) {
                return false;
            }
        }

        return true;
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
