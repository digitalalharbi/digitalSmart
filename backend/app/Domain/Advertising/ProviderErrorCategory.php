<?php

declare(strict_types=1);

namespace App\Domain\Advertising;

/**
 * Normalised provider failure kinds.
 *
 * Every platform has its own error vocabulary; what the operator needs to know
 * is which of these it was, because that determines who can fix it. An `auth`
 * failure needs a reconnection by the account owner; a `rate_limit` needs
 * patience; a `permission` needs a change in the provider's own business
 * settings and nothing we can do will help.
 */
enum ProviderErrorCategory: string
{
    case Auth = 'auth';
    case RateLimit = 'rate_limit';
    case Permission = 'permission';
    case NotFound = 'not_found';
    case Validation = 'validation';
    case Transport = 'transport';
    case Server = 'server';
    case Unknown = 'unknown';

    /** Whether retrying the same request unchanged could succeed. */
    public function isTransient(): bool
    {
        return in_array($this, [self::RateLimit, self::Transport, self::Server], true);
    }

    /** Whether a person has to act before this can work again. */
    public function needsOperatorAction(): bool
    {
        return in_array($this, [self::Auth, self::Permission, self::Validation], true);
    }

    public static function fromStatusCode(?int $status): self
    {
        return match (true) {
            $status === 401, $status === 403 => self::Auth,
            $status === 404 => self::NotFound,
            $status === 429 => self::RateLimit,
            $status === 400, $status === 422 => self::Validation,
            /*
             * Retryable without anyone changing anything. X publishes both as
             * documented error codes — `CANCELLED_REQUEST` (408) and
             * `LOCK_ACQUISITION_TIMEOUT` (423), the second meaning another writer holds
             * the entity right now. Filed as unknown they would have been reported to
             * an operator as something to fix.
             */
            $status === 408 => self::Transport,
            $status === 423 => self::Server,
            $status !== null && $status >= 500 => self::Server,
            $status === null => self::Transport,
            default => self::Unknown,
        };
    }
}
