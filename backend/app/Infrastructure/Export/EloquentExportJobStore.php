<?php

declare(strict_types=1);

namespace App\Infrastructure\Export;

use App\Application\Export\Contract\ExportJobStore;
use App\Application\Export\DTO\ExportView;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Export\ExportKind;
use App\Domain\Export\ExportStatus;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

final readonly class EloquentExportJobStore implements ExportJobStore
{
    public function __construct(private IdentifierGenerator $ids) {}

    public function create(string $workspaceId, string $userId, ExportKind $kind, string $subjectId, DateTimeImmutable $at): ExportView
    {
        $id = $this->ids->generate();

        DB::table('export_jobs')->insert([
            'id' => $id, 'workspace_id' => $workspaceId, 'user_id' => $userId, 'kind' => $kind->value,
            'subject_id' => $subjectId, 'status' => ExportStatus::Queued->value,
            'requested_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);

        return $this->findForWorker($id) ?? throw new LogicException('An export that was just written could not be read.');
    }

    public function find(string $workspaceId, string $userId, string $id): ?ExportView
    {
        $row = DB::table('export_jobs')->where(['workspace_id' => $workspaceId, 'user_id' => $userId, 'id' => $id])->first();

        return $row instanceof stdClass ? $this->view($row) : null;
    }

    public function findForWorker(string $id): ?ExportView
    {
        $row = DB::table('export_jobs')->where('id', $id)->first();

        return $row instanceof stdClass ? $this->view($row) : null;
    }

    public function mine(string $workspaceId, string $userId, int $limit): array
    {
        return array_values(array_map(
            fn (stdClass $row): ExportView => $this->view($row),
            DB::table('export_jobs')->where(['workspace_id' => $workspaceId, 'user_id' => $userId])
                ->orderByDesc('requested_at')->limit($limit)->get()->all(),
        ));
    }

    public function markRunning(string $id, DateTimeImmutable $at): void
    {
        DB::table('export_jobs')->where('id', $id)->update(['status' => ExportStatus::Running->value, 'started_at' => $at, 'updated_at' => $at]);
    }

    public function markReady(string $id, string $path, string $filename, int $byteSize, DateTimeImmutable $at, DateTimeImmutable $expiresAt): void
    {
        DB::table('export_jobs')->where('id', $id)->update([
            'status' => ExportStatus::Ready->value, 'storage_path' => $path, 'filename' => $filename, 'byte_size' => $byteSize,
            'finished_at' => $at, 'expires_at' => $expiresAt, 'error' => null, 'updated_at' => $at,
        ]);
    }

    public function markFailed(string $id, string $message, DateTimeImmutable $at): void
    {
        DB::table('export_jobs')->where('id', $id)->update([
            'status' => ExportStatus::Failed->value, 'error' => $message, 'finished_at' => $at, 'updated_at' => $at,
        ]);
    }

    public function lapsed(DateTimeImmutable $now, int $limit): array
    {
        $out = [];

        foreach (DB::table('export_jobs')->whereNotNull('storage_path')->where('expires_at', '<=', $now)->limit($limit)->get(['id', 'storage_path']) as $r) {
            $out[] = ['id' => (string) $r->id, 'path' => (string) $r->storage_path];
        }

        return $out;
    }

    public function clearFile(string $id): void
    {
        DB::table('export_jobs')->where('id', $id)->update(['storage_path' => null]);
    }

    public function path(string $id): ?string
    {
        $path = DB::table('export_jobs')->where('id', $id)->value('storage_path');

        return is_string($path) ? $path : null;
    }

    private function view(stdClass $r): ExportView
    {
        return new ExportView(
            id: (string) $r->id,
            workspaceId: (string) $r->workspace_id,
            userId: (string) $r->user_id,
            kind: ExportKind::from((string) $r->kind),
            subjectId: (string) $r->subject_id,
            status: ExportStatus::from((string) $r->status),
            filename: is_string($r->filename) ? $r->filename : null,
            byteSize: $r->byte_size === null ? null : (int) $r->byte_size,
            error: is_string($r->error) ? $r->error : null,
            requestedAt: new DateTimeImmutable((string) $r->requested_at),
            finishedAt: is_string($r->finished_at) ? new DateTimeImmutable($r->finished_at) : null,
            expiresAt: is_string($r->expires_at) ? new DateTimeImmutable($r->expires_at) : null,
        );
    }
}
