<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce\Webhook;

use App\Application\Commerce\Contract\WebhookSignatureVerifier;
use App\Domain\Integration\Provider;

/**
 * Routes a signature check to the platform that knows how to do it.
 *
 * One entry today. The indirection earns its place immediately all the same: a webhook endpoint with a
 * `match` over providers inside it is an endpoint where adding Zid means editing the security-critical
 * path, and the second platform's rules would be written next to the first platform's assumptions.
 *
 * **An unrecognised provider verifies nothing.** False rather than true, so a platform added to the
 * registry without a verifier rejects its webhooks visibly instead of accepting anything that arrives.
 */
final readonly class StoreWebhookVerifiers implements WebhookSignatureVerifier
{
    public function __construct(private SallaWebhookVerifier $salla) {}

    public function verify(
        Provider $provider,
        string $rawBody,
        ?string $signature,
        ?string $strategy,
        ?string $secret,
    ): bool {
        return match ($provider) {
            Provider::Salla => $this->salla->verify($rawBody, $signature, $strategy, $secret),
            default => false,
        };
    }
}
