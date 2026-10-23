<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Tracking;

use App\Application\Tracking\Command\DeleteFunnel;
use App\Application\Tracking\Command\SaveFunnel;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Application\Tracking\Query\GetTrackingAnalytics;
use App\Interface\Http\Controller\Controller;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Funnels and what the first-party record says about them. */
final class TrackingAnalyticsController extends Controller
{
    public function funnels(Request $request, GetTrackingAnalytics $read): JsonResponse
    {
        $project = $request->query('project_id');

        return response()->json(['data' => $read->funnels(is_string($project) ? $project : null)]);
    }

    public function saveFunnel(Request $request, SaveFunnel $save, ?string $funnel = null): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'stages' => ['required', 'array', 'min:2', 'max:12'],
            'stages.*' => ['array'],
        ]);

        try {
            $saved = $save((string) $data['project_id'], (string) $data['name'], array_values($data['stages']), $funnel);
        } catch (TrackingRefused $e) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        }

        return response()->json(['data' => $saved], $funnel === null ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function deleteFunnel(string $funnel, DeleteFunnel $delete): JsonResponse
    {
        $delete($funnel);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function funnel(Request $request, GetTrackingAnalytics $read): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'funnel_id' => ['nullable', 'uuid'],
            'link_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->run(fn (): array => $read->funnel(
            (string) $data['project_id'], $data['funnel_id'] ?? null, $data['link_id'] ?? null, $this->day($data, 'from'), $this->day($data, 'to'),
        ));
    }

    public function breakdown(Request $request, GetTrackingAnalytics $read): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'dimension' => ['required', 'string'],
            'link_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->run(fn (): array => $read->breakdown(
            (string) $data['project_id'], (string) $data['dimension'], $data['link_id'] ?? null, $this->day($data, 'from'), $this->day($data, 'to'),
        ));
    }

    public function followUp(Request $request, GetTrackingAnalytics $read): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'sla_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->run(fn (): array => $read->followUp(
            (string) $data['project_id'], $this->day($data, 'from'), $this->day($data, 'to'), (int) ($data['sla_minutes'] ?? 60),
        ));
    }

    public function leadTimeline(string $lead, GetTrackingAnalytics $read): JsonResponse
    {
        return response()->json(['data' => $read->leadTimeline($lead)]);
    }

    /** @param callable(): array<string, mixed> $fn */
    private function run(callable $fn): JsonResponse
    {
        try {
            return response()->json(['data' => $fn()]);
        } catch (TrackingRefused $e) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        }
    }

    /** @param array<string, mixed> $data */
    private function day(array $data, string $key): ?DateTimeImmutable
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? new DateTimeImmutable($value.' 00:00:00', new DateTimeZone('UTC')) : null;
    }
}
