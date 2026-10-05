<?php

declare(strict_types=1);

namespace App\Application\Integration\Exception;

use App\Domain\Integration\Provider;
use RuntimeException;
use Throwable;

/**
 * A request to the provider failed.
 *
 * Adapters normalise their own error shapes into this one, so callers can act
 * on `isRetryable()` and `retryAfterSeconds()` without knowing whose API
 * returned what.
 */
final class ProviderRequestFailed extends RuntimeException
{
    public function __construct(
        public readonly Provider $provider,
        string $message,
        public readonly ?int $statusCode = null,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function rateLimited(Provider $provider, ?int $retryAfterSeconds = null): self
    {
        return new self(
            $provider,
            sprintf('%s rate-limited this request.', $provider->label()),
            429,
            true,
            $retryAfterSeconds,
        );
    }

    public static function unauthorised(Provider $provider, string $detail): self
    {
        // Not retryable: a new token is needed, not another attempt.
        return new self($provider, sprintf('%s rejected the credentials: %s', $provider->label(), $detail), 401);
    }

    public static function transport(Provider $provider, string $detail, ?Throwable $previous = null): self
    {
        return new self($provider, sprintf('%s could not be reached: %s', $provider->label(), $detail), null, true, null, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
