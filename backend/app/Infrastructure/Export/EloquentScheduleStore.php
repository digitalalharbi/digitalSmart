<?php

declare(strict_types=1);

namespace App\Infrastructure\Export;

use App\Application\Export\Contract\ScheduleStore;
use App\Application\Export\DTO\ScheduleView;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Export\ExportKind;
use App\Domain\Export\ScheduleCadence;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class EloquentScheduleStore implements ScheduleStore
{
    public function __construct(private IdentifierGenerator $ids) {}

    public function create(
        string $workspaceId,
        string $userId,
        string $reportId,
        ExportKind $kind,
        ScheduleCadence $cadence,
        int $hour,
        ?int $weekday,
        ?int $dayOfMonth,
        DateTimeImmutable $nextRunAt,
        DateTimeImmutable $at,
    ): ScheduleView {
        $id = $this->ids->generate();

        DB::table('report_schedules')->insert([
            'id' => $id, 'workspace_id' => $workspaceId, 'user_id' => $userId, 'report_id' => $reportId,
            'kind' => $kind->value, 'cadence' => $cadence->value, 'hour' => $hour,
            'weekday' => $cadence === ScheduleCadence::Weekly ? ($weekday ?? 1) : null,
            'day_of_month' => $cadence === ScheduleCadence::Monthly ? min(max($dayOfMonth ?? 1, 1), 28) : null,
            'next_run_at' => $nextRunAt, 'created_at' => $at, 'updated_at' => $at,
        ]);

        $row = DB::table('report_schedules')->where('id', $id)->first();

        return $this->view($row instanceof stdClass ? $row : new stdClass);
    }

    public function mine(string $workspaceId, string $userId, ?string $reportId): array
    {
        $query = DB::table('report_schedules')->where(['workspace_id' => $workspaceId, 'user_id' => $userId]);

        if ($reportId !== null) {
            $query->where('report_id', $reportId);
        }

        return array_values(array_map($this->view(...), $query->orderBy('next_run_at')->get()->all()));
    }

    public function isOwnedBy(string $workspaceId, string $id, string $userId): bool
    {
        return DB::table('report_schedules')->where(['workspace_id' => $workspaceId, 'id' => $id, 'user_id' => $userId])->exists();
    }

    public function delete(string $workspaceId, string $id): void
    {
        DB::table('report_schedules')->where(['workspace_id' => $workspaceId, 'id' => $id])->delete();
    }

    public function due(DateTimeImmutable $now, int $limit): array
    {
        return array_values(array_map(
            $this->view(...),
            DB::table('report_schedules')->whereNotNull('next_run_at')->where('next_run_at', '<=', $now)
                ->orderBy('next_run_at')->limit($limit)->get()->all(),
        ));
    }

    public function ran(string $id, DateTimeImmutable $at, DateTimeImmutable $nextRunAt): void
    {
        DB::table('report_schedules')->where('id', $id)->update([
            'last_run_at' => $at, 'next_run_at' => $nextRunAt, 'last_error' => null, 'updated_at' => $at,
        ]);
    }

    public function halt(string $id, string $reason, DateTimeImmutable $at): void
    {
        DB::table('report_schedules')->where('id', $id)->update(['next_run_at' => null, 'last_error' => $reason, 'updated_at' => $at]);
    }

    private function view(stdClass $r): ScheduleView
    {
        return new ScheduleView(
            id: (string) ($r->id ?? ''),
            workspaceId: (string) ($r->workspace_id ?? ''),
            userId: (string) ($r->user_id ?? ''),
            reportId: (string) ($r->report_id ?? ''),
            kind: ExportKind::from((string) ($r->kind ?? 'report_csv')),
            cadence: ScheduleCadence::from((string) ($r->cadence ?? 'daily')),
            hour: (int) ($r->hour ?? 6),
            weekday: isset($r->weekday) ? (int) $r->weekday : null,
            dayOfMonth: isset($r->day_of_month) ? (int) $r->day_of_month : null,
            nextRunAt: isset($r->next_run_at) ? new DateTimeImmutable((string) $r->next_run_at) : null,
            lastRunAt: isset($r->last_run_at) ? new DateTimeImmutable((string) $r->last_run_at) : null,
            lastError: isset($r->last_error) ? (string) $r->last_error : null,
        );
    }
}
