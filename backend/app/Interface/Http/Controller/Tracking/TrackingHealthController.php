<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Tracking;

use App\Application\Tracking\Command\SaveDataLayerMap;
use App\Application\Tracking\Command\StartTrackingTest;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Application\Tracking\Query\GetTrackingHealthReport;
use App\Application\Tracking\Query\GetTrackingSnippet;
use App\Application\Tracking\Query\ListDebugEvents;
use App\Interface\Http\Controller\Controller;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Is tracking working, the test console, and the data layer snippet. */
final class TrackingHealthController extends Controller
{
    public function health(Request $request, GetTrackingHealthReport $report): JsonResponse
    {
        $data = $request->validate(['project_id' => ['required', 'uuid'], 'link_id' => ['nullable', 'uuid']]);

        return response()->json(['data' => $report((string) $data['project_id'], $data['link_id'] ?? null)]);
    }

    public function startTest(string $link, StartTrackingTest $start): JsonResponse
    {
        try {
            return response()->json(['data' => $start($link)], Response::HTTP_CREATED);
        } catch (TrackingRefused $e) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        }
    }

    public function events(Request $request, ListDebugEvents $list): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'touchpoint' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{20}$/'],
            'after' => ['nullable', 'string', 'max:40'],
        ]);

        $after = null;

        if (isset($data['after'])) {
            try {
                $after = new DateTimeImmutable((string) $data['after']);
            } catch (Exception) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Unreadable time.');
            }
        }

        return response()->json(['data' => $list((string) $data['project_id'], $data['touchpoint'] ?? null, $after)]);
    }

    public function snippet(Request $request, GetTrackingSnippet $get): JsonResponse
    {
        $data = $request->validate(['project_id' => ['required', 'uuid']]);

        return response()->json(['data' => $get((string) $data['project_id'], rtrim((string) config('digitalsmart.tracking.public_base_url'), '/'))]);
    }

    public function saveDataLayer(Request $request, SaveDataLayerMap $save): JsonResponse
    {
        $data = $request->validate(['project_id' => ['required', 'uuid'], 'names' => ['required', 'array'], 'names.*' => ['nullable', 'string', 'max:60']]);

        try {
            return response()->json(['data' => ['data_layer' => $save((string) $data['project_id'], $data['names'])]]);
        } catch (TrackingRefused $e) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        }
    }
}
