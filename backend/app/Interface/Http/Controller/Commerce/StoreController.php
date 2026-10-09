<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Commerce;

use App\Application\Commerce\Command\ConnectStore;
use App\Application\Commerce\Command\ReadStoreNow;
use App\Application\Commerce\DTO\ConnectableStore;
use App\Application\Commerce\DTO\StoreView;
use App\Application\Commerce\Query\ListConnectableStores;
use App\Application\Commerce\Query\ListStores;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Request\Commerce\ConnectStoreRequest;
use App\Interface\Http\Resource\Commerce\StoreResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The stores a workspace reads.
 *
 * The half of the commerce module that was missing. A store row could only be written by a seeder, so a
 * workspace that connected Salla had a connection and no store — and every order its webhooks delivered was
 * rejected as `unknown_store`.
 */
final class StoreController extends Controller
{
    /** Which stores this workspace reads, and when each was last read. */
    public function index(Request $request, ListStores $listStores): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (StoreView $store): array => StoreResource::store($store),
                $listStores($request->string('client_id')->value() ?: null),
            ),
        ]);
    }

    /**
     * Every store the workspace's commerce connections can read.
     *
     * Empty when nothing is connected, which is the ordinary state of a checkout rather than a failure: the
     * integrations page is where a platform is connected, and this says nothing about whether it is.
     */
    public function connectable(ListConnectableStores $listConnectable): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (ConnectableStore $store): array => StoreResource::connectable($store),
                $listConnectable(),
            ),
        ]);
    }

    /**
     * Say that this merchant's orders are this client's revenue.
     *
     * The store's name, currency, domain and timezone are read back from the platform rather than accepted from
     * the request: every amount this store reports is stored in its currency, which makes it the single most
     * expensive field in the product to take on trust.
     */
    public function store(ConnectStoreRequest $request, ConnectStore $connect): JsonResponse
    {
        return response()->json([
            'data' => StoreResource::store($connect(
                $request->provider(),
                $request->string('external_id')->value(),
                $request->string('client_id')->value(),
            )),
        ], Response::HTTP_CREATED);
    }

    /**
     * Read one store now.
     *
     * Answers with the outcome rather than `204`: succeeded, partial, failed and skipped are four different
     * facts, and a partial read wrote real orders that are kept.
     */
    public function read(string $store, ReadStoreNow $readNow): JsonResponse
    {
        return response()->json(['data' => StoreResource::readResult($readNow($store))]);
    }
}
