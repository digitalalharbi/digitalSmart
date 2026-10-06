<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Measurement;

use App\Application\Measurement\Command\DeleteEventDefinition;
use App\Application\Measurement\Command\DeleteMeasurementSource;
use App\Application\Measurement\Command\SaveEventDefinition;
use App\Application\Measurement\Command\SaveMeasurementSource;
use App\Application\Measurement\DTO\EventDefinitionView;
use App\Application\Measurement\DTO\MeasurementSourceView;
use App\Application\Measurement\Query\GetTrackingHealth;
use App\Application\Measurement\Query\ListEventDefinitions;
use App\Application\Measurement\Query\ListMeasurementSources;
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

    private function scopeId(Request $request, string $key): ?string
    {
        return $request->string($key)->value() ?: null;
    }
}
