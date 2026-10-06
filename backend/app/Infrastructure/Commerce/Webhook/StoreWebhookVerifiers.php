<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce\Webhook;

use App\Application\Commerce\Contract\WebhookSignatureVerifier;
use App\Domain\Integration\Provider;

/**
 * Routes a verification to the platform that knows how to do it.
 *
 * Two entries, and they do genuinely different things: Salla proves the *body* was not altered, Zid
 * proves only that the caller knows a password. Keeping them apart is what stops the weaker mechanism's
 * assumptions leaking into the stronger one's code — and what makes the difference visible to anybody
 * reading either.
 *
 * **An unrecognised provider verifies nothing.** False rather than true, so a platform added to the
 * registry without a verifier rejects its webhooks visibly instead of accepting whatever arrives.
 */
final readonly class StoreWebhookVerifiers implements WebhookSignatureVerifier
{
    public function __construct(
        private SallaWebhookVerifier $salla,
        private ZidWebhookVerifier $zid,
    ) {}

    /** @param array<string, string|null> $headers */
    public function verify(Provider $provider, string $rawBody, array $headers, ?string $secret): bool
    {
        return match ($provider) {
            Provider::Salla => $this->salla->verify($rawBody, $headers, $secret),
            Provider::Zid => $this->zid->verify($headers, $secret),
            default => false,
        };
    }
}
