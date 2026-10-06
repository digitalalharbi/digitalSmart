<?php

declare(strict_types=1);

namespace App\Application\Commerce\Contract;

use App\Domain\Integration\Provider;

/**
 * Whether a webhook really came from the provider it claims.
 *
 * A port rather than a concrete class, because each platform signs differently — Salla hashes the raw
 * body with a per-merchant secret, and Zid will do something else — and the receiving use case must
 * not grow a `match` over providers. The implementations live in Infrastructure with the provider's
 * documentation beside them.
 */
interface WebhookSignatureVerifier
{
    public function verify(
        Provider $provider,
        string $rawBody,
        ?string $signature,
        ?string $strategy,
        ?string $secret,
    ): bool;
}
