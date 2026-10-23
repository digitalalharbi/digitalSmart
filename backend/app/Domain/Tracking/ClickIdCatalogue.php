<?php

declare(strict_types=1);

namespace App\Domain\Tracking;

use App\Domain\Integration\Provider;

/**
 * The advertising click identifiers this product will keep, and nothing else.
 *
 * Each is a parameter that provider's own documentation names for its auto-tagging or click attribution. A parameter
 * that is merely common in the wild is not listed: keeping an undocumented identifier would store something this
 * product cannot explain, and "we captured it" would be read as "it means something".
 *
 * A click identifier is evidence that an ad was clicked. It is never evidence that anything was *bought*.
 */
final class ClickIdCatalogue
{
    /** @var array<string, Provider> parameter name (lower case) => provider that documents it */
    private const array PARAMETERS = [
        'gclid' => Provider::GoogleAds,
        'gbraid' => Provider::GoogleAds,
        'wbraid' => Provider::GoogleAds,
        'fbclid' => Provider::Meta,
        'ttclid' => Provider::TikTok,
        'sccid' => Provider::Snapchat,
        'li_fat_id' => Provider::LinkedIn,
        'twclid' => Provider::X,
    ];

    /** Longest value kept. Real identifiers are far shorter; anything longer is not one. */
    private const int MAX_LENGTH = 512;

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, string> parameter => value, for the documented ones present
     */
    public static function from(array $query): array
    {
        $found = [];

        foreach ($query as $name => $value) {
            $key = strtolower((string) $name);

            if (! isset(self::PARAMETERS[$key]) || ! is_string($value) || $value === '' || strlen($value) > self::MAX_LENGTH) {
                continue;
            }

            if (preg_match('/^[A-Za-z0-9_\-\.\~\+\/=]+$/', $value) !== 1) {
                continue;
            }

            $found[$key] = $value;
        }

        return $found;
    }

    public static function provider(string $parameter): ?Provider
    {
        return self::PARAMETERS[strtolower($parameter)] ?? null;
    }

    /** @return list<string> */
    public static function parameters(): array
    {
        return array_keys(self::PARAMETERS);
    }
}
