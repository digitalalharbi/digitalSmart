<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

/**
 * The secret in a one-time link: 32 random bytes, URL-safe. Stored only as an HMAC under the application key, so a
 * copy of the table — or of a backup — cannot be turned back into a working link.
 */
final class ApprovalLinkToken
{
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token, string $key): string
    {
        return hash_hmac('sha256', $token, $key);
    }

    public static function wellFormed(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }
}
