<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce\Webhook;

use App\Domain\Integration\Provider;

/**
 * Decides whether a webhook really came from Salla.
 *
 * Salla documents two strategies and sends which one it used in a header:
 *
 *  - **Signature** — `X-Salla-Signature` holds `HMAC-SHA256(secret, rawRequestBody)`, hex encoded.
 *    This is what Partner apps get by default.
 *  - **Token** — the header holds the webhook's secret token itself, compared directly.
 *
 * Three rules, each of which has been the cause of a real vulnerability in somebody's integration:
 *
 *  1. **Hash the raw body, never a re-encoded one.** `json_decode` followed by `json_encode` changes
 *     key order, unicode escaping and float formatting, and the hash of the result will not match. An
 *     implementation that re-encodes appears to work until a payload contains an Arabic product name,
 *     and then rejects every webhook from every Arabic store.
 *  2. **Compare in constant time.** `===` on a hash leaks its contents through timing, one byte at a
 *     time, to anybody who can send requests and measure. `hash_equals` exists for this.
 *  3. **An unconfigured secret verifies nothing.** With no secret this returns false rather than true,
 *     so a deployment that forgot to set one rejects webhooks instead of accepting forgeries. The
 *     failure mode is a tracking gap somebody notices, not a silent door.
 */
final readonly class SallaWebhookVerifier
{
    public const string HEADER_STRATEGY = 'X-Salla-Security-Strategy';

    public const string HEADER_SIGNATURE = 'X-Salla-Signature';

    private const string STRATEGY_SIGNATURE = 'signature';

    private const string STRATEGY_TOKEN = 'token';

    public function provider(): Provider
    {
        return Provider::Salla;
    }

    /**
     * Whether this request is genuinely from Salla.
     *
     * @param  string  $rawBody  the body exactly as it arrived, before any decoding
     * @param  array<string, string|null>  $headers  lower-cased header names
     */
    public function verify(string $rawBody, array $headers, ?string $secret): bool
    {
        $signature = $headers[strtolower(self::HEADER_SIGNATURE)] ?? null;
        $strategy = $headers[strtolower(self::HEADER_STRATEGY)] ?? null;

        if ($secret === null || $secret === '' || $signature === null || $signature === '') {
            /*
             * Nothing to check against, or nothing to check. False either way: refusing a legitimate
             * webhook is a gap somebody will notice and fix, while accepting an unverifiable one is a
             * door nobody will notice at all.
             */
            return false;
        }

        return match (strtolower(trim($strategy ?? self::STRATEGY_SIGNATURE))) {
            self::STRATEGY_TOKEN => hash_equals($secret, $signature),
            self::STRATEGY_SIGNATURE => hash_equals(
                // Hex, lower case, over the untouched bytes of the request.
                hash_hmac('sha256', $rawBody, $secret),
                strtolower(trim($signature)),
            ),
            /*
             * A strategy this product does not recognise is not a strategy it can verify. Returning
             * false for an unknown value means a future Salla strategy causes rejected webhooks and a
             * visible gap, rather than accepted ones and an invisible hole.
             */
            default => false,
        };
    }
}
