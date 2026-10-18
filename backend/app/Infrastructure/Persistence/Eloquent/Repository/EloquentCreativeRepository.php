<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Creative\Contract\CreativeRepository;
use App\Application\Creative\DTO\CommentInput;
use App\Application\Creative\DTO\CreativeCommentView;
use App\Application\Creative\DTO\CreativeRequestInput;
use App\Application\Creative\DTO\CreativeRequestView;
use App\Application\Creative\DTO\CreativeVersionView;
use App\Application\Creative\DTO\VersionInput;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Creative\ApprovalOutcome;
use App\Domain\Creative\AssetKind;
use App\Domain\Creative\AssetMeasurements;
use App\Domain\Creative\CommentVisibility;
use App\Domain\Creative\CreativeState;
use DateTimeImmutable;
use Exception;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Creative requests, versions, approvals and comments.
 *
 * Two things here are worth reading before changing anything.
 *
 * **Version numbers are assigned inside a transaction**, from the current maximum. Two people uploading at
 * once must not produce two version threes; the unique constraint on `(request, number)` turns that race
 * into a failed write rather than a quietly reordered approval trail.
 *
 * **An approval is written against a version id**, and the latest decision per version is read back by
 * ordering on `decided_at`. There is no approval column on the request, so there is no way to express the
 * thing this module refuses to express: a request that is approved while its newest version is unseen.
 */
final readonly class EloquentCreativeRepository implements CreativeRepository
{
    public function __construct(private IdentifierGenerator $identifiers) {}

    public function open(string $workspaceId, CreativeRequestInput $input, ?string $actorId): CreativeRequestView
    {
        $id = $this->identifiers->generate();

        DB::table('creative_requests')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'client_id' => $input->clientId,
            'project_id' => $input->projectId,
            'title' => $input->title,
            'brief' => $input->brief,
            'state' => CreativeState::Draft->value,
            'kind' => $input->kind->value,
            'provider' => $input->provider,
            'placement' => $input->placement,
            'due_at' => $input->dueAt,
            'owner_id' => $input->ownerId,
            'created_by' => $actorId,
            'state_changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->find($workspaceId, null, $id) ?? throw new Exception('The request was not written.');
    }

    public function find(string $workspaceId, ?array $visibleProjectIds, string $requestId): ?CreativeRequestView
    {
        $row = $this->scope($workspaceId, $visibleProjectIds)->where('r.id', $requestId)->first();

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $values */
        $values = (array) $row;

        return $this->view($values, $workspaceId);
    }

    public function assignOwner(string $workspaceId, string $requestId, ?string $ownerId): CreativeRequestView
    {
        DB::table('creative_requests')
            ->where('workspace_id', $workspaceId)
            ->where('id', $requestId)
            ->update(['owner_id' => $ownerId, 'updated_at' => now()]);

        /*
         * `find` with no visibility narrowing, like every other write here: the use case has already resolved
         * the request through the actor's own scope, and narrowing twice would answer null for a record it has
         * just confirmed.
         */
        return $this->find($workspaceId, null, $requestId)
            ?? throw new Exception('The request disappeared while being assigned.');
    }

    public function move(
        string $workspaceId,
        string $requestId,
        CreativeState $state,
        ?string $cancelledReason,
    ): CreativeRequestView {
        DB::table('creative_requests')
            ->where('workspace_id', $workspaceId)
            ->where('id', $requestId)
            ->update([
                'state' => $state->value,
                /*
                 * Kept when moving away from cancelled rather than cleared: the reason something was
                 * cancelled is still the reason it was cancelled, even after somebody reopened it.
                 */
                'cancelled_reason' => $state === CreativeState::Cancelled
                    ? $cancelledReason
                    : DB::raw('cancelled_reason'),
                'state_changed_at' => now(),
                'updated_at' => now(),
            ]);

        return $this->find($workspaceId, null, $requestId)
            ?? throw new Exception('The request disappeared while being moved.');
    }

    public function addVersion(
        string $workspaceId,
        string $requestId,
        VersionInput $input,
        ?string $actorId,
    ): CreativeRequestView {
        DB::transaction(function () use ($workspaceId, $requestId, $input, $actorId): void {
            /*
             * The next number, read inside the transaction. The unique constraint is the real guard — this
             * only decides what to try — and a concurrent upload fails loudly rather than renumbering
             * somebody else's version.
             */
            $next = 1 + (int) DB::table('creative_versions')
                ->where('workspace_id', $workspaceId)
                ->where('creative_request_id', $requestId)
                ->max('number');

            DB::table('creative_versions')->insert([
                'id' => $this->identifiers->generate(),
                'workspace_id' => $workspaceId,
                'creative_request_id' => $requestId,
                'number' => $next,
                'kind' => $input->kind->value,
                'label' => $input->label,
                'storage_path' => $input->storagePath,
                'body' => $input->body,
                'width_px' => $input->measurements->widthPx,
                'height_px' => $input->measurements->heightPx,
                'file_size_bytes' => $input->measurements->fileSizeBytes,
                'duration_seconds' => $input->measurements->durationSeconds,
                'format' => $input->measurements->format,
                'text_length' => $input->measurements->textLength,
                'unmeasured_reason' => $input->measurements->unmeasuredReason,
                'uploaded_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $this->find($workspaceId, null, $requestId)
            ?? throw new Exception('The request disappeared while a version was added.');
    }

    public function decide(
        string $workspaceId,
        string $versionId,
        ApprovalOutcome $outcome,
        ?string $reason,
        ?string $actorId,
    ): CreativeRequestView {
        $requestId = $this->requestIdForVersion($workspaceId, $versionId)
            ?? throw new Exception('The version does not belong to this workspace.');

        DB::transaction(function () use ($workspaceId, $versionId, $requestId, $outcome, $reason, $actorId): void {
            /*
             * Appended rather than replaced. A reviewer who sends work back and later approves the same
             * file has made two decisions, and the trail is the record of both — overwriting the first
             * would lose the fact that it was ever rejected.
             */
            DB::table('creative_approvals')->insert([
                'id' => $this->identifiers->generate(),
                'workspace_id' => $workspaceId,
                'creative_version_id' => $versionId,
                'outcome' => $outcome->value,
                'reason' => $reason,
                'decided_by' => $actorId,
                'decided_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('creative_requests')
                ->where('workspace_id', $workspaceId)
                ->where('id', $requestId)
                ->update([
                    'state' => $outcome->state()->value,
                    'state_changed_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return $this->find($workspaceId, null, $requestId)
            ?? throw new Exception('The request disappeared while being decided.');
    }

    public function comment(
        string $workspaceId,
        string $requestId,
        CommentInput $input,
        ?string $actorId,
    ): CreativeRequestView {
        DB::table('creative_comments')->insert([
            'id' => $this->identifiers->generate(),
            'workspace_id' => $workspaceId,
            'creative_request_id' => $requestId,
            'creative_version_id' => $input->versionId,
            'visibility' => $input->visibility->value,
            'body' => $input->body,
            'author_id' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->find($workspaceId, null, $requestId)
            ?? throw new Exception('The request disappeared while being commented on.');
    }

    public function board(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId,
        int $limitPerColumn,
        ?string $projectId = null,
    ): array {
        $columns = [];
        $rows = [];

        foreach (CreativeState::cases() as $state) {
            /*
             * One query per column rather than one query sliced in PHP, so the limit applies per column —
             * a board whose first column holds two hundred drafts must still show the three things waiting
             * for review.
             */
            $query = $this->scope($workspaceId, $visibleProjectIds)
                ->where('r.state', $state->value)
                ->orderByRaw('r.due_at asc nulls last')
                ->orderByDesc('r.created_at')
                ->limit($limitPerColumn);

            if ($clientId !== null) {
                $query->where('r.client_id', $clientId);
            }

            if ($projectId !== null) {
                $query->where('r.project_id', $projectId);
            }

            $rows[$state->value] = $query->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        /*
         * What every card needs of its files, in two statements for the whole board: how many versions, and the
         * shape of the newest. Loading the versions themselves would make the board's cost grow with the
         * history rather than with the work in flight.
         */
        $ids = [];

        foreach ($rows as $stateRows) {
            foreach ($stateRows as $values) {
                $ids[] = (string) ($values['id'] ?? '');
            }
        }

        $previews = $this->previews($workspaceId, $ids);

        foreach ($rows as $state => $stateRows) {
            $requests = [];

            foreach ($stateRows as $values) {
                $requests[] = $this->view($values, $workspaceId, withDetail: false, preview: $previews[(string) ($values['id'] ?? '')] ?? null);
            }

            $columns[$state] = $requests;
        }

        return $columns;
    }

    public function requestIdForVersion(string $workspaceId, string $versionId): ?string
    {
        $row = DB::table('creative_versions')
            ->where('workspace_id', $workspaceId)
            ->where('id', $versionId)
            ->first(['creative_request_id']);

        return $row === null ? null : (string) $row->creative_request_id;
    }

    /**
     * @param  list<string>  $requestIds
     * @return array<string, array{count: int, width: ?int, height: ?int, format: ?string}>
     */
    private function previews(string $workspaceId, array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        $counts = DB::table('creative_versions')
            ->where('workspace_id', $workspaceId)
            ->whereIn('creative_request_id', $requestIds)
            ->groupBy('creative_request_id')
            ->selectRaw('creative_request_id, count(*) as total')
            ->pluck('total', 'creative_request_id');

        $latest = DB::table('creative_versions')
            ->where('workspace_id', $workspaceId)
            ->whereIn('creative_request_id', $requestIds)
            ->orderBy('creative_request_id')
            ->orderByDesc('number')
            ->selectRaw('distinct on (creative_request_id) creative_request_id, width_px, height_px, format')
            ->get();

        $out = [];

        foreach ($latest as $row) {
            $out[(string) $row->creative_request_id] = [
                'count' => (int) ($counts[$row->creative_request_id] ?? 0),
                'width' => $this->integer($row->width_px),
                'height' => $this->integer($row->height_px),
                'format' => $this->text($row->format),
            ];
        }

        return $out;
    }

    /** @param list<string>|null $visibleProjectIds */
    private function scope(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        $builder = DB::table('creative_requests as r')
            ->join('clients as c', 'c.id', '=', 'r.client_id')
            ->leftJoin('projects as p', 'p.id', '=', 'r.project_id')
            ->leftJoin('users as o', 'o.id', '=', 'r.owner_id')
            ->where('r.workspace_id', $workspaceId)
            ->select([
                'r.*',
                'c.name as client_name',
                'p.name as project_name',
                'o.name as owner_name',
            ]);

        if ($visibleProjectIds === null) {
            return $builder;
        }

        if ($visibleProjectIds === []) {
            return $builder->whereRaw('1 = 0');
        }

        /*
         * A request reaches a person through its project, and a request with no project through its
         * client's projects — the same rule the measurement sources and the stores use, written the same
         * way so three surfaces cannot disagree about who can see what.
         */
        return $builder->where(function (Builder $scope) use ($workspaceId, $visibleProjectIds): void {
            $scope->whereIn('r.project_id', $visibleProjectIds)
                ->orWhere(function (Builder $unassigned) use ($workspaceId, $visibleProjectIds): void {
                    $unassigned->whereNull('r.project_id')
                        ->whereIn('r.client_id', function (Builder $sub) use ($workspaceId, $visibleProjectIds): void {
                            $sub->select('client_id')
                                ->from('projects')
                                ->where('workspace_id', $workspaceId)
                                ->whereIn('id', $visibleProjectIds);
                        });
                });
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array{count: int, width: ?int, height: ?int, format: ?string}|null  $preview
     */
    private function view(array $values, string $workspaceId, bool $withDetail = true, ?array $preview = null): CreativeRequestView
    {
        $id = (string) ($values['id'] ?? '');

        return new CreativeRequestView(
            id: $id,
            clientId: (string) ($values['client_id'] ?? ''),
            clientName: (string) ($values['client_name'] ?? ''),
            title: (string) ($values['title'] ?? ''),
            state: CreativeState::tryFrom((string) ($values['state'] ?? '')) ?? CreativeState::Draft,
            kind: AssetKind::tryFrom((string) ($values['kind'] ?? '')) ?? AssetKind::Image,
            createdAt: $this->date($values['created_at'] ?? null) ?? new DateTimeImmutable('@0'),
            projectId: $this->text($values['project_id'] ?? null),
            projectName: $this->text($values['project_name'] ?? null),
            brief: $this->text($values['brief'] ?? null),
            provider: $this->text($values['provider'] ?? null),
            placement: $this->text($values['placement'] ?? null),
            dueAt: $this->date($values['due_at'] ?? null),
            ownerId: $this->text($values['owner_id'] ?? null),
            ownerName: $this->text($values['owner_name'] ?? null),
            cancelledReason: $this->text($values['cancelled_reason'] ?? null),
            stateChangedAt: $this->date($values['state_changed_at'] ?? null),
            versions: $withDetail ? $this->versions($workspaceId, $id) : [],
            comments: $withDetail ? $this->comments($workspaceId, $id) : [],
            versionCount: $preview['count'] ?? 0,
            previewWidthPx: $preview['width'] ?? null,
            previewHeightPx: $preview['height'] ?? null,
            previewFormat: $preview['format'] ?? null,
        );
    }

    /** @return list<CreativeVersionView> */
    private function versions(string $workspaceId, string $requestId): array
    {
        $rows = DB::table('creative_versions as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.uploaded_by')
            ->where('v.workspace_id', $workspaceId)
            ->where('v.creative_request_id', $requestId)
            ->orderBy('v.number')
            ->get(['v.*', 'u.name as uploaded_by_name']);

        $decisions = $this->latestDecisions($workspaceId, $requestId);
        $versions = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $id = (string) ($values['id'] ?? '');
            $decision = $decisions[$id] ?? null;

            $versions[] = new CreativeVersionView(
                id: $id,
                number: (int) ($values['number'] ?? 0),
                kind: AssetKind::tryFrom((string) ($values['kind'] ?? '')) ?? AssetKind::Image,
                measurements: new AssetMeasurements(
                    widthPx: $this->integer($values['width_px'] ?? null),
                    heightPx: $this->integer($values['height_px'] ?? null),
                    fileSizeBytes: $this->integer($values['file_size_bytes'] ?? null),
                    durationSeconds: isset($values['duration_seconds']) && is_numeric($values['duration_seconds'])
                        ? (float) $values['duration_seconds']
                        : null,
                    format: $this->text($values['format'] ?? null),
                    textLength: $this->integer($values['text_length'] ?? null),
                    unmeasuredReason: $this->text($values['unmeasured_reason'] ?? null),
                ),
                createdAt: $this->date($values['created_at'] ?? null) ?? new DateTimeImmutable('@0'),
                label: $this->text($values['label'] ?? null),
                storagePath: $this->text($values['storage_path'] ?? null),
                body: $this->text($values['body'] ?? null),
                uploadedByName: $this->text($values['uploaded_by_name'] ?? null),
                decision: $decision === null
                    ? null
                    : ApprovalOutcome::tryFrom((string) $decision['outcome']),
                decisionReason: $decision === null ? null : $this->text($decision['reason']),
                decidedByName: $decision === null ? null : $this->text($decision['decided_by_name']),
                decidedAt: $decision === null ? null : $this->date($decision['decided_at']),
            );
        }

        return $versions;
    }

    /**
     * The newest decision per version.
     *
     * Newest rather than only: a reviewer who sends work back and later approves the same file has made two
     * decisions, both kept, and the one that stands is the last.
     *
     * @return array<string, array<string, mixed>>
     */
    private function latestDecisions(string $workspaceId, string $requestId): array
    {
        $rows = DB::table('creative_approvals as a')
            ->join('creative_versions as v', 'v.id', '=', 'a.creative_version_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.decided_by')
            ->where('a.workspace_id', $workspaceId)
            ->where('v.creative_request_id', $requestId)
            ->orderBy('a.decided_at')
            ->get(['a.creative_version_id', 'a.outcome', 'a.reason', 'a.decided_at', 'u.name as decided_by_name']);

        $latest = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            // Ordered ascending, so the last write for a version wins.
            $latest[(string) ($values['creative_version_id'] ?? '')] = $values;
        }

        return $latest;
    }

    /** @return list<CreativeCommentView> */
    private function comments(string $workspaceId, string $requestId): array
    {
        $rows = DB::table('creative_comments as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.author_id')
            ->leftJoin('creative_versions as v', 'v.id', '=', 'm.creative_version_id')
            ->where('m.workspace_id', $workspaceId)
            ->where('m.creative_request_id', $requestId)
            ->orderBy('m.created_at')
            ->get(['m.*', 'u.name as author_name', 'v.number as version_number']);

        $comments = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $comments[] = new CreativeCommentView(
                id: (string) ($values['id'] ?? ''),
                visibility: CommentVisibility::tryFrom((string) ($values['visibility'] ?? ''))
                    // The safe default for a row whose value somehow does not parse. Never the client.
                    ?? CommentVisibility::Internal,
                body: (string) ($values['body'] ?? ''),
                createdAt: $this->date($values['created_at'] ?? null) ?? new DateTimeImmutable('@0'),
                authorName: $this->text($values['author_name'] ?? null),
                versionNumber: $this->integer($values['version_number'] ?? null),
            );
        }

        return $comments;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (Exception) {
                return null;
            }
        }

        return null;
    }
}
