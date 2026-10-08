<?php

declare(strict_types=1);

namespace App\Domain\Integration;

/**
 * Why a write did not happen, in this product's own words.
 *
 * Six providers, six vocabularies of error. Normalising them here is not tidiness: it is what lets a surface say
 * something a person can act on, and what stops "the write failed" being the only thing anybody is ever told.
 *
 * The distinction that matters most is between **`Rejected`** and **`Unknown`**. A rejection is the provider
 * saying no — a budget below its floor, a campaign in a state that cannot be resumed — and the change definitely
 * did not happen. `Unknown` means the request may or may not have landed: a timeout, a connection dropped
 * mid-flight, a response this product could not parse. The two must never be collapsed, because a reader told
 * "it failed" will try again, and trying again after an `Unknown` is how a budget gets set twice.
 */
enum WriteFailure: string
{
    /** The credentials are missing, expired or lack the scope. Nothing was attempted. */
    case NotAuthorised = 'not_authorised';

    /** The campaign or account does not exist, or is not visible to these credentials. */
    case NotFound = 'not_found';

    /** The provider understood and said no. The change did not happen. */
    case Rejected = 'rejected';

    /** The provider's own state has moved since the request was approved. */
    case Conflict = 'conflict';

    case RateLimited = 'rate_limited';

    /** The provider is down or overloaded. Safe to try again. */
    case Unavailable = 'unavailable';

    /**
     * It is not known whether the change happened.
     *
     * Its own case, and never folded into the others. A reader told "it failed" tries again; trying again after
     * this is how a budget gets set twice.
     */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::NotAuthorised => 'Not authorised',
            self::NotFound => 'Not found on the platform',
            self::Rejected => 'The platform refused it',
            self::Conflict => 'The platform\'s state has moved',
            self::RateLimited => 'Rate limited',
            self::Unavailable => 'The platform is unavailable',
            self::Unknown => 'It is not known whether this happened',
        };
    }

    /** What a person should do next. */
    public function guidance(): string
    {
        return match ($this) {
            self::NotAuthorised => 'Reconnect the platform, then carry this out again. Nothing was changed.',
            self::NotFound => 'Check the campaign still exists on the platform. Nothing was changed.',
            self::Rejected => 'The platform gave a reason below. Nothing was changed — amend the request and ask '
                .'for approval again.',
            self::Conflict => 'Somebody changed this on the platform after the request was approved. Read the '
                .'current state and raise a fresh request.',
            self::RateLimited => 'Wait and carry it out again. Nothing was changed.',
            self::Unavailable => 'Try again shortly. Nothing was changed.',
            self::Unknown => 'Do **not** carry this out again until somebody has looked at the platform. The '
                .'change may have landed, and repeating it could apply it twice.',
        };
    }

    /**
     * Whether carrying the same request out again is safe.
     *
     * False for `Unknown`, which is the whole reason that case exists. False for `Rejected` too: the provider
     * has already said no, and repeating the request puts the same question twice rather than fixing it.
     */
    public function isSafeToRetry(): bool
    {
        return match ($this) {
            self::NotAuthorised, self::NotFound, self::RateLimited, self::Unavailable => true,
            self::Rejected, self::Conflict, self::Unknown => false,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
