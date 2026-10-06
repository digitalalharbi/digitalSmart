<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Commerce;

use App\Application\Commerce\Command\ReceiveStoreWebhook;
use App\Domain\Integration\Provider;
use App\Interface\Http\Controller\Controller;
use App\Interface\Queue\ProcessStoreWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where store platforms deliver events.
 *
 * Unauthenticated by necessity and verified by signature: a provider has no session and cannot be
 * given one. The endpoint is therefore written to be boring — read the raw body, hand it to the use
 * case, dispatch a job, answer.
 *
 * **Everything is answered with a 2xx, including the refusals.** A non-2xx makes a provider redeliver,
 * and redelivering a payload whose signature does not verify achieves nothing except filling the
 * journal. A rejected delivery is recorded, not retried.
 *
 * **The raw body is what gets hashed.** `$request->getContent()` rather than the parsed array:
 * decoding and re-encoding changes key order, unicode escaping and float formatting, and the hash of
 * the result will not match. An implementation that re-encodes appears to work until a payload carries
 * an Arabic product name — and then rejects every webhook from every Arabic store, which is most of
 * them here.
 */
final class StoreWebhookController extends Controller
{
    public function __invoke(string $provider, Request $request, ReceiveStoreWebhook $receive): JsonResponse
    {
        $resolved = Provider::tryFrom($provider);

        if ($resolved === null) {
            // Not a provider this product knows. A 404 rather than a 400: there is no such endpoint.
            return response()->json(['message' => 'Unknown provider.'], Response::HTTP_NOT_FOUND);
        }

        $rawBody = $request->getContent();

        /** @var array<string, mixed> $payload */
        $payload = is_array($decoded = json_decode($rawBody, true)) ? $decoded : [];

        $receipt = $receive(
            provider: $resolved,
            payload: $payload,
            rawBody: $rawBody,
            signature: $request->header('X-Salla-Signature'),
            strategy: $request->header('X-Salla-Security-Strategy'),
        );

        if ($receipt->shouldProcess() && $receipt->eventId !== null) {
            /*
             * Queued, never processed inline. The provider is waiting on this response, and reading an
             * order from the API inside the request would turn one slow call into a redelivery and then
             * into a queue of duplicates.
             */
            ProcessStoreWebhookJob::dispatch($receipt->eventId);
        }

        return response()->json(['outcome' => $receipt->outcome], Response::HTTP_OK);
    }
}
