<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Tracking;

use App\Application\Tracking\Command\DeleteConversionMapping;
use App\Application\Tracking\Command\RetryDispatch;
use App\Application\Tracking\Command\SaveConversionMapping;
use App\Application\Tracking\Command\SaveTrackingPolicy;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Application\Tracking\Query\GetConversions;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Which provider event each digitalSmart event becomes, what was sent, and the consent and retention policy. */
final class ConversionController extends Controller
{
    public function show(Request $request, GetConversions $get): JsonResponse
    {
        $data = $request->validate(['project_id' => ['required', 'uuid']]);

        return response()->json(['data' => $get((string) $data['project_id'])]);
    }

    public function saveMapping(Request $request, SaveConversionMapping $save): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'event' => ['required', 'string', 'max:40'],
            'provider' => ['required', 'string', 'max:32'],
            'provider_event' => ['required', 'string', 'max:190'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        try {
            $saved = $save((string) $data['project_id'], (string) $data['event'], (string) $data['provider'], (string) $data['provider_event'], (bool) ($data['enabled'] ?? true));
        } catch (TrackingRefused $e) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        }

        return response()->json(['data' => $saved], Response::HTTP_CREATED);
    }

    public function deleteMapping(Request $request, string $mapping, DeleteConversionMapping $delete): JsonResponse
    {
        $data = $request->validate(['project_id' => ['required', 'uuid']]);
        $delete((string) $data['project_id'], $mapping);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function savePolicy(Request $request, SaveTrackingPolicy $save): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'consent_policy' => ['required', 'string', 'max:12'],
            'retention_days' => ['required', 'integer'],
        ]);

        try {
            $save((string) $data['project_id'], (string) $data['consent_policy'], (int) $data['retention_days']);
        } catch (TrackingRefused $e) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        }

        return response()->json(['data' => ['saved' => true]]);
    }

    public function retry(string $dispatch, RetryDispatch $retry): JsonResponse
    {
        $retry($dispatch);

        return response()->json(['data' => ['queued' => true]], Response::HTTP_ACCEPTED);
    }
}
