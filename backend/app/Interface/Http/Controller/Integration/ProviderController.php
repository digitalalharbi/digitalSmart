<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Integration;

use App\Application\Integration\Command\CheckConnectionHealth;
use App\Application\Integration\Command\ConfigureConnection;
use App\Application\Integration\Command\DisconnectProvider;
use App\Application\Integration\Command\DiscoverAccounts;
use App\Application\Integration\DTO\ProviderView;
use App\Application\Integration\Query\GetProviderReadiness;
use App\Application\Integration\Query\ListProviders;
use App\Domain\Integration\ExternalAccount;
use App\Domain\Integration\Provider;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Request\Integration\ConfigureConnectionRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ad platform connections for the active workspace.
 *
 * Every provider is listed, connected or not, with an honest status. Nothing
 * here reports a connection that does not exist.
 */
final class ProviderController extends Controller
{
    /** Providers, their capabilities, and where this workspace stands with each. */
    public function index(ListProviders $listProviders): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map($this->present(...), $listProviders()),
        ]);
    }

    /** Provider × level × change: what each platform documents, and how far this product has proven it. */
    public function readiness(GetProviderReadiness $readiness): JsonResponse
    {
        return new JsonResponse(['data' => $readiness()]);
    }

    /** Store or replace a provider's credentials. */
    public function configure(
        string $provider,
        ConfigureConnectionRequest $request,
        ConfigureConnection $configure,
    ): JsonResponse {
        $connection = $configure(
            $request->provider(),
            $request->credentials(),
            $request->string('name')->value() ?: null,
        );

        return new JsonResponse([
            'data' => [
                'id' => $connection->id->value,
                'provider' => $connection->provider->value,
                'status' => $connection->status()->value,
                'status_label' => $connection->status()->label(),
                // Key names only — values never leave the server.
                'credential_keys' => $connection->credentials()->describe(),
            ],
        ], Response::HTTP_CREATED);
    }

    /** Ask the provider which ad accounts these credentials can reach. */
    public function discover(string $provider, DiscoverAccounts $discover): JsonResponse
    {
        $accounts = $discover(Provider::from($provider));

        return new JsonResponse([
            'data' => array_map(static fn (ExternalAccount $account): array => [
                'id' => $account->id->value,
                'external_id' => $account->externalId,
                'name' => $account->name(),
                'currency' => $account->currency(),
                'status' => $account->status()->value,
            ], $accounts),
            'meta' => ['count' => count($accounts)],
        ]);
    }

    /** Re-check a connection and record the result. */
    public function health(string $provider, CheckConnectionHealth $check): JsonResponse
    {
        $health = $check(Provider::from($provider));

        return new JsonResponse([
            'data' => [
                'healthy' => $health->healthy,
                'status' => $health->status->value,
                'status_label' => $health->status->label(),
                'message' => $health->message,
            ],
        ]);
    }

    /** Revoke the connection and destroy its stored credentials. */
    public function destroy(string $provider, DisconnectProvider $disconnect): JsonResponse
    {
        $disconnect(Provider::from($provider));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** @return array<string, mixed> */
    private function present(ProviderView $view): array
    {
        return [
            'provider' => $view->provider,
            'label' => $view->label,
            'category' => $view->category,
            'category_label' => $view->categoryLabel,
            /*
             * Separate from `blocked_external`, which says a workspace cannot proceed.
             * This says *why*: a programme to be admitted to, an app to register, or no
             * public API at all. The three need different actions from different people.
             */
            'api_status' => $view->apiStatus,
            'api_status_label' => $view->apiStatusLabel,
            'auth_method' => $view->authMethod,
            'capabilities' => $view->capabilities,
            'entities' => $view->entities,
            'rate_limit' => $view->rateLimit,
            'documentation_url' => $view->documentationUrl,
            'connection_id' => $view->connectionId,
            'status' => $view->status,
            'status_label' => $view->statusLabel,
            'can_sync' => $view->canSync,
            'needs_attention' => $view->needsAttention,
            'status_message' => $view->statusMessage,
            'missing_credentials' => $view->missingCredentials,
            'blocked_external' => $view->isBlockedExternal,
            /*
             * Whether the stored credential is the literal `sandbox`. The page that decides a provider is
             * ready could not tell a fixture-backed connection from a live one before this.
             */
            'is_sandbox' => $view->isSandbox,
            'account_count' => $view->accountCount,
            'last_checked_at' => $view->lastCheckedAt,
            'expires_at' => $view->expiresAt,
        ];
    }
}
