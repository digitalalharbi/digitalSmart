<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Access\Contract\AccessRequestRepository;
use App\Application\Access\DTO\AccessRequestView;
use App\Domain\Access\AccessRequestNeed;
use App\Domain\Access\AccessRequestStatus;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class EloquentAccessRequestRepository implements AccessRequestRepository
{
    public function intakeWorkspaceId(): ?string
    {
        $slug = config('digitalsmart.access_requests.workspace_slug');

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $id = DB::table('workspaces')->where('slug', $slug)->value('id');

        return is_string($id) ? $id : null;
    }

    public function hasPending(string $workspaceId, string $email, DateTimeImmutable $since): bool
    {
        return DB::table('access_requests')
            ->where('workspace_id', $workspaceId)
            ->where('email', $email)
            ->where('status', AccessRequestStatus::Pending->value)
            ->where('created_at', '>=', $since->format('Y-m-d H:i:sP'))
            ->exists();
    }

    public function add(
        string $id,
        string $workspaceId,
        string $name,
        string $email,
        ?string $company,
        ?string $phone,
        AccessRequestNeed $need,
        ?string $message,
        string $locale,
        DateTimeImmutable $at,
    ): void {
        DB::table('access_requests')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'name' => $name,
            'email' => $email,
            'company' => $company,
            'phone' => $phone,
            'need' => $need->value,
            'message' => $message,
            'locale' => $locale,
            'status' => AccessRequestStatus::Pending->value,
            'created_at' => $at->format('Y-m-d H:i:sP'),
            'updated_at' => $at->format('Y-m-d H:i:sP'),
        ]);
    }

    public function list(string $workspaceId, ?AccessRequestStatus $status, int $limit): array
    {
        $query = $this->base()->where('r.workspace_id', $workspaceId);

        if ($status !== null) {
            $query->where('r.status', $status->value);
        }

        $rows = [];

        foreach ($query->orderByDesc('r.created_at')->orderByDesc('r.id')->limit($limit)->get() as $row) {
            if ($row instanceof stdClass) {
                $rows[] = $this->view($row);
            }
        }

        return $rows;
    }

    public function find(string $workspaceId, string $id): ?AccessRequestView
    {
        $row = $this->base()->where('r.workspace_id', $workspaceId)->where('r.id', $id)->first();

        return $row instanceof stdClass ? $this->view($row) : null;
    }

    public function decide(
        string $workspaceId,
        string $id,
        AccessRequestStatus $status,
        string $reviewerId,
        ?string $note,
        DateTimeImmutable $at,
    ): void {
        $decision = in_array($status, [AccessRequestStatus::Approved, AccessRequestStatus::Declined], true);

        DB::table('access_requests')->where('workspace_id', $workspaceId)->where('id', $id)->update([
            'status' => $status->value,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => $decision ? $at->format('Y-m-d H:i:sP') : null,
            'review_note' => $note,
            'updated_at' => $at->format('Y-m-d H:i:sP'),
        ]);
    }

    public function counts(string $workspaceId): array
    {
        $counts = array_fill_keys(AccessRequestStatus::values(), 0);

        foreach (DB::table('access_requests')->where('workspace_id', $workspaceId)
            ->selectRaw('status, count(*) as total')->groupBy('status')->get() as $row) {
            if ($row instanceof stdClass) {
                $counts[(string) $row->status] = (int) $row->total;
            }
        }

        return $counts;
    }

    private function base(): \Illuminate\Database\Query\Builder
    {
        return DB::table('access_requests as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.reviewed_by')
            ->select('r.*', 'u.name as reviewed_by_name');
    }

    private function view(stdClass $row): AccessRequestView
    {
        return new AccessRequestView(
            id: (string) $row->id,
            name: (string) $row->name,
            email: (string) $row->email,
            company: $row->company === null ? null : (string) $row->company,
            phone: $row->phone === null ? null : (string) $row->phone,
            need: AccessRequestNeed::from((string) $row->need),
            message: $row->message === null ? null : (string) $row->message,
            locale: (string) $row->locale,
            status: AccessRequestStatus::from((string) $row->status),
            createdAt: new DateTimeImmutable((string) $row->created_at),
            reviewedAt: $row->reviewed_at === null ? null : new DateTimeImmutable((string) $row->reviewed_at),
            reviewedByName: $row->reviewed_by_name === null ? null : (string) $row->reviewed_by_name,
            reviewNote: $row->review_note === null ? null : (string) $row->review_note,
        );
    }
}
