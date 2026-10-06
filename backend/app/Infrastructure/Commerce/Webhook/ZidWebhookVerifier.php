<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce\Webhook;

use App\Domain\Integration\Provider;

/**
 * Decides whether a webhook really came from Zid.
 *
 * **Zid does not sign its payloads.** It authenticates with HTTP Basic credentials that the partner
 * chooses when subscribing: a `username` and `password` are supplied on the subscription, and Zid sends
 * them back as `Authorization: Basic base64(username:password)` on every delivery.
 *
 * That is a weaker property than Salla's and it is worth being plain about, because the difference
 * changes what this endpoint can promise:
 *
 *  - **It proves the caller knows the password. It does not prove the body is intact.** An HMAC covers
 *    the bytes; Basic Auth covers nothing but itself. The body is trusted only as far as the transport
 *    is, which is why HTTPS is not optional here in a way it merely ought to be elsewhere.
 *  - **The secret travels on every request.** Base64 is an encoding, not encryption. The credential is
 *    effectively a bearer token and should be treated as one: rotated if a log ever captured it, and
 *    never reused as anything else.
 *  - **There is no timestamp or nonce, so there is no replay protection.** A captured delivery can be
 *    replayed forever. What makes that harmless is the same thing that makes Zid's missing event id
 *    harmless: the work is an upsert keyed on the order's own id, so replaying a delivery rewrites the
 *    same row.
 *
 * The comparison is still constant time. Basic Auth being weaker is no reason to leak the password
 * through timing on top.
 */
final readonly class ZidWebhookVerifier
{
    public const string HEADER_AUTHORIZATION = 'Authorization';

    private const string SCHEME = 'basic ';

    public function provider(): Provider
    {
        return Provider::Zid;
    }

    /**
     * @param  array<string, string|null>  $headers  lower-cased header names
     * @param  string|null  $secret  the expected `username:password`, as configured on the subscription
     */
    public function verify(array $headers, ?string $secret): bool
    {
        $header = $headers[strtolower(self::HEADER_AUTHORIZATION)] ?? null;

        if ($secret === null || $secret === '' || $header === null || $header === '') {
            /*
             * Nothing to check against, or nothing to check. False either way: a deployment that has not
             * configured the credential rejects deliveries, which somebody notices, rather than
             * accepting anything, which nobody does.
             */
            return false;
        }

        if (stripos($header, self::SCHEME) !== 0) {
            // Not Basic. A scheme this product does not recognise is not one it can verify.
            return false;
        }

        $encoded = substr($header, strlen(self::SCHEME));
        $decoded = base64_decode(trim($encoded), true);

        if ($decoded === false) {
            return false;
        }

        return hash_equals($secret, $decoded);
    }
}
