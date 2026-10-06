<?php

declare(strict_types=1);

namespace App\Domain\Integration;

/**
 * What a provider has published about how often it may be called.
 *
 * Only what is published. A provider that documents no limit gets `null` rather than a
 * guess, because an invented ceiling is worse than none: a sync that self-throttles to a
 * number nobody set is slow for a reason nobody can find, and a sync that trusts an
 * invented number gets throttled in production instead of in the retry logic.
 *
 * The `scope` matters as much as the number. Tag Manager's quarter of a request per
 * second is per *project*, so every workspace on one deployment shares it; X's 250 per
 * fifteen minutes is per *user*, so they do not.
 */
final readonly class ProviderRateLimit
{
    public function __construct(
        /** Requests allowed inside the window, where one is published. */
        public ?int $requests = null,
        public ?int $windowSeconds = null,
        /** Who the limit is counted against: `project`, `user`, `account`, `app`. */
        public ?string $scope = null,
        /** A separate daily ceiling, where one is published. */
        public ?int $dailyRequests = null,
        /** Anything else the provider states, in a sentence. */
        public ?string $note = null,
    ) {}

    /** Whether the provider publishes anything at all. */
    public function isDocumented(): bool
    {
        return $this->requests !== null || $this->dailyRequests !== null || $this->note !== null;
    }

    /**
     * Not documented, and said so.
     *
     * Salla and Zid publish no limits. Until one appears, the adapter backs off on the
     * provider's own 429 rather than pacing itself against a number we made up.
     */
    public static function undocumented(): self
    {
        return new self(note: 'The provider publishes no rate limit. Back off on its own throttling response.');
    }
}
