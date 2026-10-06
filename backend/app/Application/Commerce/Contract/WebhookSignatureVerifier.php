<?php

declare(strict_types=1);

namespace App\Application\Commerce\Contract;

use App\Domain\Integration\Provider;

/**
 * Whether a webhook really came from the provider it claims.
 *
 * A port rather than a concrete class, because the platforms do genuinely different things: Salla signs
 * the body with HMAC-SHA256 and sends the digest in its own header, while Zid sends Basic Auth
 * credentials the partner chose when subscribing. Those are not the same security property and cannot
 * be hidden behind one set of named arguments, so the whole header bag is passed and each
 * implementation takes what its documentation says to take.
 *
 * `$secret` is the one webhook credential stored on the connection, and what it holds is
 * provider-specific — the signing secret for Salla, `username:password` for Zid. Each implementation
 * documents its own.
 */
interface WebhookSignatureVerifier
{
    /**
     * @param  string  $rawBody  the body exactly as it arrived, before any decoding
     * @param  array<string, string|null>  $headers  the request's headers, lower-cased keys
     */
    public function verify(Provider $provider, string $rawBody, array $headers, ?string $secret): bool;
}
