<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Client;

use App\Application\Client\Command\ChangeClientStatus;
use App\Application\Client\Command\CreateClient;
use App\Application\Client\Command\DeleteClient;
use App\Application\Client\Command\UpdateClient;
use App\Application\Client\DTO\ClientFilters;
use App\Application\Client\Query\GetClient;
use App\Application\Client\Query\ListClients;
use App\Application\Shared\DTO\Pagination;
use App\Domain\Client\ClientId;
use App\Domain\Client\ClientStatus;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Request\Client\ChangeClientStatusRequest;
use App\Interface\Http\Request\Client\StoreClientRequest;
use App\Interface\Http\Request\Client\UpdateClientRequest;
use App\Interface\Http\Resource\ClientResource;
use App\Interface\Http\Resource\PaginatedCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clients of the active workspace.
 *
 * Each action validates, calls exactly one use case, and renders the result.
 * Scoping and permissions are enforced inside the use case.
 */
final class ClientController extends Controller
{
    /** List clients in the active workspace. */
    public function index(Request $request, ListClients $listClients): JsonResponse
    {
        $page = $listClients(
            new ClientFilters(
                search: $request->string('search')->value() ?: null,
                statuses: $this->statuses($request),
                isDemo: $request->has('is_demo') ? $request->boolean('is_demo') : null,
                sort: $request->string('sort', 'name')->value(),
                direction: $request->string('direction', 'asc')->value(),
            ),
            new Pagination(
                page: $request->integer('page', 1),
                perPage: $request->integer('per_page', Pagination::DEFAULT_PER_PAGE),
            ),
        );

        return PaginatedCollection::response(
            $page,
            static fn (object $client): array => ClientResource::make($client)->resolve(),
        );
    }

    /** A single client. */
    public function show(string $client, GetClient $getClient): JsonResponse
    {
        return ClientResource::make($getClient(new ClientId($client)))->response();
    }

    /** Create a client. It starts as a prospect. */
    public function store(StoreClientRequest $request, CreateClient $createClient): JsonResponse
    {
        return ClientResource::make($createClient($request->toInput()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** Update a client's details. */
    public function update(string $client, UpdateClientRequest $request, UpdateClient $updateClient): JsonResponse
    {
        return ClientResource::make($updateClient(new ClientId($client), $request->toInput()))->response();
    }

    /** Move a client through its lifecycle. Illegal transitions are rejected. */
    public function changeStatus(
        string $client,
        ChangeClientStatusRequest $request,
        ChangeClientStatus $changeStatus,
    ): JsonResponse {
        return ClientResource::make($changeStatus(new ClientId($client), $request->status()))->response();
    }

    /** Soft-delete a client, retaining its history. */
    public function destroy(string $client, DeleteClient $deleteClient): JsonResponse
    {
        $deleteClient(new ClientId($client));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** @return list<ClientStatus> */
    private function statuses(Request $request): array
    {
        $raw = $request->input('status', []);
        $values = is_array($raw) ? $raw : [$raw];

        $statuses = [];

        foreach ($values as $value) {
            if (is_string($value)) {
                $status = ClientStatus::tryFrom($value);

                if ($status instanceof ClientStatus) {
                    $statuses[] = $status;
                }
            }
        }

        return $statuses;
    }
}
