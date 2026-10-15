<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Export;

use App\Application\Export\Command\DeleteSchedule;
use App\Application\Export\Command\RequestExport;
use App\Application\Export\Command\ScheduleReport;
use App\Application\Export\DTO\ExportView;
use App\Application\Export\DTO\ScheduleView;
use App\Application\Export\Query\DownloadExport;
use App\Application\Export\Query\ListExports;
use App\Application\Export\Query\ListSchedules;
use App\Domain\Export\ExportKind;
use App\Domain\Export\ScheduleCadence;
use App\Interface\Http\Controller\Controller;
use App\Interface\Queue\RunExportJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Heavy files, made in the background and fetched when ready.
 */
final class ExportController extends Controller
{
    public function index(ListExports $list): JsonResponse
    {
        return response()->json(['data' => array_map($this->present(...), $list())]);
    }

    public function store(Request $request, RequestExport $export): JsonResponse
    {
        $kind = ExportKind::tryFrom($request->string('kind')->value());
        $subject = $request->string('subject_id')->value();

        if ($kind === null || $subject === '') {
            return response()->json([
                'message' => 'Say what to export and which report.',
                'code' => 'invalid_export',
                'allowed' => ExportKind::values(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $job = $export($kind, $subject);

        RunExportJob::dispatch($job->id, $job->workspaceId, $job->userId);

        return response()->json(['data' => $this->present($job)], Response::HTTP_ACCEPTED);
    }

    public function schedules(Request $request, ListSchedules $list): JsonResponse
    {
        $report = $request->string('report_id')->value();

        return response()->json(['data' => array_map($this->presentSchedule(...), $list($report === '' ? null : $report))]);
    }

    public function schedule(Request $request, ScheduleReport $schedule): JsonResponse
    {
        $validated = $request->validate([
            'report_id' => ['required', 'uuid'],
            'kind' => ['required', 'in:'.implode(',', ExportKind::values())],
            'cadence' => ['required', 'in:'.implode(',', ScheduleCadence::values())],
            'hour' => ['sometimes', 'integer', 'between:0,23'],
            'weekday' => ['sometimes', 'nullable', 'integer', 'between:0,6'],
            'day_of_month' => ['sometimes', 'nullable', 'integer', 'between:1,28'],
        ]);

        $created = $schedule(
            (string) $validated['report_id'],
            ExportKind::from((string) $validated['kind']),
            ScheduleCadence::from((string) $validated['cadence']),
            isset($validated['hour']) ? (int) $validated['hour'] : 6,
            isset($validated['weekday']) ? (int) $validated['weekday'] : null,
            isset($validated['day_of_month']) ? (int) $validated['day_of_month'] : null,
        );

        return response()->json(['data' => $this->presentSchedule($created)], Response::HTTP_CREATED);
    }

    public function unschedule(string $schedule, DeleteSchedule $delete): JsonResponse
    {
        $delete($schedule);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function download(string $export, DownloadExport $download): HttpResponse
    {
        $file = $download($export);

        return response($file->bytes, Response::HTTP_OK, [
            'Content-Type' => $file->contentType,
            'Content-Length' => (string) strlen($file->bytes),
            'Content-Disposition' => sprintf('attachment; filename="%s"', $file->filename),
        ]);
    }

    /** @return array<string, mixed> */
    private function presentSchedule(ScheduleView $s): array
    {
        return [
            'id' => $s->id,
            'report_id' => $s->reportId,
            'kind' => $s->kind->value,
            'cadence' => $s->cadence->value,
            'hour_utc' => $s->hour,
            'weekday' => $s->weekday,
            'day_of_month' => $s->dayOfMonth,
            'next_run_at' => $s->nextRunAt?->format(DATE_ATOM),
            'last_run_at' => $s->lastRunAt?->format(DATE_ATOM),
            'last_error' => $s->lastError,
            'is_halted' => $s->nextRunAt === null,
        ];
    }

    /** @return array<string, mixed> */
    private function present(ExportView $v): array
    {
        return [
            'id' => $v->id,
            'kind' => $v->kind->value,
            'subject_id' => $v->subjectId,
            'status' => $v->status->value,
            'filename' => $v->filename,
            'byte_size' => $v->byteSize,
            'error' => $v->error,
            'requested_at' => $v->requestedAt->format(DATE_ATOM),
            'finished_at' => $v->finishedAt?->format(DATE_ATOM),
            'expires_at' => $v->expiresAt?->format(DATE_ATOM),
        ];
    }
}
