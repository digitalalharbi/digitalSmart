<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Integration;

use App\Application\Integration\DTO\ExternalAccountView;
use App\Application\Integration\Query\ListExternalAccounts;
use App\Domain\Integration\Provider;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ExternalAccountController extends Controller
{
    /** Ad accounts discovered in this workspace, with how many projects use each. */
    public function index(Request $request, ListExternalAccounts $listAccounts): JsonResponse
    {
        $provider = $request->string('provider')->value();

        $accounts = $listAccounts($provider !== '' ? Provider::tryFrom($provider) : null);

        return new JsonResponse([
            'data' => array_map(static fn (ExternalAccountView $account): array => [
                'id' => $account->id,
                'provider' => $account->provider,
                'provider_label' => $account->providerLabel,
                'external_id' => $account->externalId,
                'name' => $account->name,
                'currency' => $account->currency,
                'timezone' => $account->timezone,
                'status' => $account->status,
                'is_bindable' => $account->isBindable,
                'binding_count' => $account->bindingCount,
                'discovered_at' => $account->discoveredAt,
            ], $accounts),
        ]);
    }
}
