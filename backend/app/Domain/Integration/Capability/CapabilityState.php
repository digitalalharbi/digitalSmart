<?php

declare(strict_types=1);

namespace App\Domain\Integration\Capability;

/**
 * What the product knows about one capability on one platform. **`unverified` is not a weaker `documented`**: it means nobody here
 * has read the platform's current page for it, and nothing may be attempted on it.
 */
enum CapabilityState: string
{
    /** The platform's current official documentation states it; the page and version are recorded. */
    case Documented = 'documented';
    /** It may exist; it has not been confirmed on an official page. Nothing is attempted. */
    case Unverified = 'unverified';
    /** The official documentation says it cannot be done, or there is no API for it. */
    case Unsupported = 'unsupported';
    /** Documented, but it needs an approval or access level only the platform can grant. */
    case BlockedExternal = 'blocked_external';

    /** Only a documented capability may be relied on by a launch. */
    public function allowsAttempt(): bool
    {
        return $this === self::Documented;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
