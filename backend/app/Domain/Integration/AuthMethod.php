<?php

declare(strict_types=1);

namespace App\Domain\Integration;

enum AuthMethod: string
{
    case OAuth2 = 'oauth2';
    case OAuth1 = 'oauth1';
    /** The provider's authorisation model is not yet known to us. */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::OAuth2 => 'OAuth 2.0',
            self::OAuth1 => 'OAuth 1.0a',
            self::Unknown => 'Not yet published',
        };
    }
}
