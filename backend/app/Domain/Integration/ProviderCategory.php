<?php

declare(strict_types=1);

namespace App\Domain\Integration;

/**
 * What kind of thing a provider is.
 *
 * The category decides where a provider appears, what questions it can answer, and
 * what it must never be mixed with. An advertising platform's revenue figure and a
 * store's invoice are both "revenue" and are not the same number; keeping the category
 * on the provider is the first line of defence against adding them together.
 */
enum ProviderCategory: string
{
    case Advertising = 'advertising';
    case Analytics = 'analytics';
    case TagManagement = 'tag_management';
    case Commerce = 'commerce';

    public function label(): string
    {
        return match ($this) {
            self::Advertising => 'Advertising',
            self::Analytics => 'Analytics',
            self::TagManagement => 'Tag management',
            self::Commerce => 'Commerce',
        };
    }

    /**
     * Whether figures from this category may be totalled with figures from another.
     *
     * Never, across categories. Platform-reported revenue, analytics revenue and store
     * revenue answer different questions, and a total that mixed them would be
     * indefensible to the client paying for it.
     */
    public function totalsWith(self $other): bool
    {
        return $this === $other;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
