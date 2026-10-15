<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Export;

use App\Application\Export\Command\RequestExport;
use App\Application\Export\DTO\ExportView;
use App\Application\Export\Query\DownloadExport;
use App\Application\Export\Query\ListExports;
use App\Domain\Export\ExportKind;
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
