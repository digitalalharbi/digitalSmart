<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Launch;

/**
 * What an approval names: the plan's version and every write it would make, hashed in a canonical form. If any of it changes —
 * an edit, an account's currency, a platform's translation — the fingerprint changes and the approval no longer applies.
 */
final class LaunchFingerprint
{
    /** @param array<string, mixed> $material */
    public static function of(array $material): string
    {
        return hash('sha256', json_encode(self::canonical($material), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonical(...), $value);
        }

        ksort($value);

        return array_map(self::canonical(...), $value);
    }
}
