<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Measurement;

use App\Application\Measurement\Command\DeleteEventDefinition;
use App\Application\Measurement\Command\DeleteMeasurementSource;
use App\Application\Measurement\Command\SaveEventDefinition;
use App\Application\Measurement\Command\SaveMeasurementSource;
use App\Application\Measurement\Command\SyncMeasurementSource;
use App\Application\Measurement\DTO\ConversionSuggestion;
use App\Application\Measurement\DTO\DiscoveredContainer;
use App\Application\Measurement\DTO\DiscoveredProperty;
use App\Application\Measurement\DTO\EventDefinitionView;
use App\Application\Measurement\DTO\MeasurementSourceView;
use App\Application\Measurement\Query\DiscoverAnalyticsProperties;
use App\Application\Measurement\Query\GetContainerReadiness;
use App\Application\Measurement\Query\GetTrackingHealth;
use App\Application\Measurement\Query\ListEventDefinitions;
use App\Application\Measurement\Query\ListMeasurementSources;
use App\Application\Measurement\Query\ListTagContainers;
use App\Application\Measurement\Query\SuggestConversions;
use App\Domain\Integration\Provider;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Request\Measurement\EventDefinitionRequest;
use App\Interface\Http\Request\Measurement\MeasurementSourceRequest;
use App\Interface\Http\Resource\Measurement\MeasurementResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What measures a client's work, what counts as success there, and whether it is working.
 */
final class MeasurementController extends Controller
{
    public function sources(Request $request, ListMeasurementSources $listSources): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (MeasurementSourceView $source): array => MeasurementResource::source($source),
                $listSources($this->scopeId($request, 'client_id'), $this->scopeId($request, 'project_id')),
            ),
        ]);
    }

    public function storeSource(MeasurementSourceRequest $request, SaveMeasurementSource $save): JsonResponse
    {
        return response()->json(
            ['data' => MeasurementResource::source($save($request->toInput()))],
            Response::HTTP_CREATED,
        );
    }

    public function updateSource(
        string $source,
        MeasurementSourceRequest $request,
        SaveMeasurementSource $save,
    ): JsonResponse {
        return response()->json(['data' => MeasurementResource::source($save($request->toInput(), $source))]);
    }

    public function destroySource(string $source, DeleteMeasurementSource $delete): JsonResponse
    {
        $delete($source);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    public function definitions(Request $request, ListEventDefinitions $listDefinitions): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (EventDefinitionView $definition): array => MeasurementResource::definition($definition),
                $listDefinitions($this->scopeId($request, 'client_id'), $this->scopeId($request, 'project_id')),
            ),
        ]);
    }

    public function storeDefinition(EventDefinitionRequest $request, SaveEventDefinition $save): JsonResponse
    {
        return response()->json(
            ['data' => MeasurementResource::definition($save($request->toInput()))],
            Response::HTTP_CREATED,
        );
    }

    public function updateDefinition(
        string $definition,
        EventDefinitionRequest $request,
        SaveEventDefinition $save,
    ): JsonResponse {
        return response()->json([
            'data' => MeasurementResource::definition($save($request->toInput(), $definition)),
        ]);
    }

    public function destroyDefinition(string $definition, DeleteEventDefinition $delete): JsonResponse
    {
        $delete($definition);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * The tracking centre's answer for one scope.
     *
     * Recomputed on every request rather than stored: a diagnosis is wrong the moment the next
     * sync lands, and a stale warning teaches people to ignore the list.
     */
    public function health(Request $request, GetTrackingHealth $health): JsonResponse
    {
        return response()->json([
            'data' => MeasurementResource::health(
                $health($this->scopeId($request, 'client_id'), $this->scopeId($request, 'project_id')),
            ),
        ]);
    }

    /**
     * Every property the workspace's connection can read.
     *
     * Unfiltered on purpose: the person choosing which property measures which client has to see
     * what the account actually holds, including the ones for clients the agency has left.
     */
    public function properties(string $provider, DiscoverAnalyticsProperties $discover): JsonResponse
    {
        $resolved = Provider::tryFrom($provider);

        if ($resolved === null) {
            return response()->json(['message' => 'Unknown provider.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => array_map(
                static fn (DiscoveredProperty $property): array => MeasurementResource::property($property),
                $discover($resolved),
            ),
        ]);
    }

    /**
     * Candidate conversions from what the property already treats as a key event.
     *
     * Suggestions only. Nothing is counted until somebody saves a definition, which is the act
     * that carries an author and an audit entry.
     */
    public function suggestions(string $source, SuggestConversions $suggest): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (ConversionSuggestion $suggestion): array => MeasurementResource::suggestion($suggestion),
                $suggest($source),
            ),
        ]);
    }

    /**
     * Read a source now and write what it reported.
     *
     * Synchronous because it is a person pressing a button and waiting for an answer; the
     * scheduled path is a queued job over the same use case. The response carries the honesty
     * label and the caveats, so a caller cannot show an estimate as a total.
     */
    public function sync(string $source, SyncMeasurementSource $sync): JsonResponse
    {
        return response()->json(['data' => MeasurementResource::syncResult($sync($source))]);
    }

    /**
     * Every tag container the workspace's connection can read.
     *
     * The published state of one of them is a separate call: listing containers is cheap, and
     * reading each one's live version is not.
     */
    public function containers(ListTagContainers $list): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (DiscoveredContainer $container): array => MeasurementResource::container($container),
                $list(),
            ),
        ]);
    }

    /**
     * Whether a client's container is actually doing anything.
     *
     * Separate from `measurement/health` on purpose. Health is derived from this product's own
     * tables and always answers; readiness needs a live call to Tag Manager, which can be slow,
     * rate-limited or unauthorised. Folding one into the other would let a provider outage empty
     * the tracking page of facts it already had.
     */
    public function readiness(string $source, GetContainerReadiness $readiness): JsonResponse
    {
        return response()->json(['data' => MeasurementResource::readiness($readiness($source))]);
    }

    private function scopeId(Request $request, string $key): ?string
    {
        return $request->string($key)->value() ?: null;
    }
}
