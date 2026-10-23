<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Dispatch;

/** What this product has established about a provider's conversion path, from that provider's own documentation. */
enum ConversionSupport: string
{
    /** The mechanism was read in the provider's documentation and is implemented against it. */
    case Documented = 'DOCUMENTED';

    /** The provider very likely has one and nobody here has read it. Not a smaller yes: a mapping is refused. */
    case Unverified = 'UNVERIFIED';

    /** No server conversion path that digitalSmart implements. */
    case Unsupported = 'UNSUPPORTED';

    public function allowsMapping(): bool
    {
        return $this === self::Documented;
    }
}
