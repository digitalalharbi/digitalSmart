<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Operations\Contract\ApprovalFilter;
use App\Application\Operations\Contract\ApprovalRepository;
use App\Application\Operations\DTO\ApprovalInput;
use App\Application\Operations\DTO\ApprovalView;
use App\Application\Operations\DTO\TaskSubjectView;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Operations\ApprovalAge;
use App\Domain\Operations\ApprovalKind;
use App\Domain\Operations\ApprovalLoad;
use App\Domain\Operations\ApprovalState;
use App\Domain\Operations\EnactmentState;
use App\Domain\Operations\ProposalFingerprint;
use App\Domain\Operations\TaskSubjectType;
use DateTimeImmutable;
use Exception;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Approval requests.
 *
 * Two things worth reading first.
 *
 * **The fingerprint is computed here, never accepted from a caller.** A client that could supply its own hash
 * could supply one matching a proposal nobody stored, and the whole guarantee — that a decision names what it
 * decided on — would be a field anybody could set.
 *
 * **The ages are computed in PHP, against one `$now`.** They come from two different clocks depending on the
 * state: a pending request is aged from when it was asked, and an approved one awaiting a hand from **when it
 * was approved**, because the question then is how long the change has gone unmade. A single `CASE` in SQL
 * would be a second copy of that rule, and the second copy is the one that forgets.
 */
final readonly class EloquentApprovalRepository implements ApprovalRepository
{
    public function __construct(private IdentifierGenerator $identifiers) {}

    public function missingReference(string $workspaceId, ?array $visibleProjectIds, ApprovalInput $input): ?string
    {
        if ($input->clientId !== null) {
            $client = DB::table('clients')->where('workspace_id', $workspaceId)->where('id', $input->clientId);

            if ($visibleProjectIds !== null) {
                $client->whereExists(static fn ($projects) => $projects
                    ->selectRaw('1')->from('projects')
                    ->whereColumn('projects.client_id', 'clients.id')
                    ->whereIn('projects.id', $visibleProjectIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $visibleProjectIds));
            }

            if (! $client->exists()) {
                return 'Client';
            }
        }

        if ($input->projectId !== null) {
            $project = DB::table('projects')->where('workspace_id', $workspaceId)->where('id', $input->projectId);

            if ($input->clientId !== null) {
                $project->where('client_id', $input->clientId);
            }

            if (! $project->exists()) {
                return 'Project';
            }
        }

        if ($input->subjectType !== null && $input->subjectId !== null
            && ! DB::table($input->subjectType->table())->where('workspace_id', $workspaceId)->where('id', $input->subjectId)->exists()) {
            return 'Subject';
        }

        return null;
    }

    public function request(string $workspaceId, ApprovalInput $input, ?string $actorId): ApprovalView
    {
        $id = $this->identifiers->generate();

        DB::table('approval_requests')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'client_id' => $input->clientId,
            'project_id' => $input->projectId,
            'kind' => $input->kind->value,
            'title' => $input->title,
            'rationale' => $input->rationale,
            'proposal' => json_encode($input->proposal, JSON_THROW_ON_ERROR),
            'proposal_fingerprint' => ProposalFingerprint::of($input->proposal)->value,
            'state' => ApprovalState::Pending->value,
            'enactment' => EnactmentState::NotRequired->value,
            'subject_type' => $input->subjectType?->value,
            'subject_id' => $input->subjectId,
            'requested_by' => $actorId,
            'supersedes_id' => $input->supersedesId,
            'origin' => $input->origin,
            'agent' => $input->agent,
            'evidence' => $input->evidence === null ? null : json_encode($input->evidence, JSON_THROW_ON_ERROR),
            'risk' => $input->risk,
            'expires_at' => $input->expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->find($workspaceId, null, $id) ?? throw new Exception('The request was not written.');
    }

    public function find(string $workspaceId, ?array $visibleProjectIds, string $requestId): ?ApprovalView
    {
        $row = $this->scope($workspaceId, $visibleProjectIds)->where('a.id', $requestId)->first();

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $values */
        $values = (array) $row;

        $views = $this->views([$values]);

        return $views[0] ?? null;
    }

    public function decide(
        string $workspaceId,
        string $requestId,
        ApprovalState $state,
        ?string $reason,
        ?string $actorId,
        string $fingerprint,
        DateTimeImmutable $at,
        EnactmentState $enactment,
    ): ApprovalView {
        DB::table('approval_requests')
            ->where('workspace_id', $workspaceId)
            ->where('id', $requestId)
            ->update([
                'state' => $state->value,
                'decided_by' => $actorId,
                'decided_at' => $at,
                'decision_reason' => $reason,
                /* The evidence that whoever decided this read this proposal. A CHECK requires it. */
                'decided_fingerprint' => $fingerprint,
                'enactment' => $enactment->value,
                'updated_at' => now(),
            ]);

        return $this->reread($workspaceId, $requestId);
    }

    public function withdraw(string $workspaceId, string $requestId, string $reason): ApprovalView
    {
        DB::table('approval_requests')
            ->where('workspace_id', $workspaceId)
            ->where('id', $requestId)
            ->update([
                'state' => ApprovalState::Withdrawn->value,
                'withdrawn_reason' => $reason,
                'updated_at' => now(),
            ]);

        return $this->reread($workspaceId, $requestId);
    }

    public function amend(
        string $workspaceId,
        string $requestId,
        string $title,
        string $rationale,
        array $proposal,
    ): ApprovalView {
        DB::table('approval_requests')
            ->where('workspace_id', $workspaceId)
            ->where('id', $requestId)
            ->update([
                'title' => $title,
                'rationale' => $rationale,
                'proposal' => json_encode($proposal, JSON_THROW_ON_ERROR),
                // Moves with the content, which is what makes the check on a decision real.
                'proposal_fingerprint' => ProposalFingerprint::of($proposal)->value,
                'updated_at' => now(),
            ]);

        return $this->reread($workspaceId, $requestId);
    }

    public function recordEnactment(
        string $workspaceId,
        string $requestId,
        EnactmentState $enactment,
        ?string $note,
        ?string $actorId,
        DateTimeImmutable $at,
    ): ApprovalView {
        DB::table('approval_requests')
            ->where('workspace_id', $workspaceId)
            ->where('id', $requestId)
            ->update([
                'enactment' => $enactment->value,
                'enacted_by' => $actorId,
                'enacted_at' => $at,
                'enactment_note' => $note,
                'updated_at' => now(),
            ]);

        return $this->reread($workspaceId, $requestId);
    }

    public function pending(
        string $workspaceId,
        ?array $visibleProjectIds,
        ApprovalFilter $filter,
        int $limit,
    ): array {
        return $this->list(
            $this->filtered($workspaceId, $visibleProjectIds, $filter)
                ->where('a.state', ApprovalState::Pending->value)
                /*
                 * Oldest first. A queue sorted newest-first hides exactly the row somebody needs to see, which
                 * is the one that has been waiting since last week.
                 */
                ->orderBy('a.created_at'),
            $limit,
        );
    }

    public function outstanding(
        string $workspaceId,
        ?array $visibleProjectIds,
        ApprovalFilter $filter,
        int $limit,
    ): array {
        return $this->list(
            $this->filtered($workspaceId, $visibleProjectIds, $filter)
                ->where('a.state', ApprovalState::Approved->value)
                ->where('a.enactment', EnactmentState::AwaitingHand->value)
                // By decision date: the age that matters here is how long the change has gone unmade.
                ->orderBy('a.decided_at'),
            $limit,
        );
    }

    public function settled(
        string $workspaceId,
        ?array $visibleProjectIds,
        ApprovalFilter $filter,
        int $limit,
    ): array {
        return $this->list(
            $this->filtered($workspaceId, $visibleProjectIds, $filter)
                ->whereIn('a.state', [
                    ApprovalState::Approved->value,
                    ApprovalState::Declined->value,
                    ApprovalState::Withdrawn->value,
                ])
                ->orderByDesc('a.updated_at'),
            $limit,
        );
    }

    public function load(
        string $workspaceId,
        ?array $visibleProjectIds,
        ApprovalFilter $filter,
        DateTimeImmutable $now,
    ): ApprovalLoad {
        $rows = $this->narrow($this->visible($workspaceId, $visibleProjectIds), $filter)
            ->groupBy('a.state', 'a.enactment', 'a.kind')
            ->get([
                'a.state',
                'a.enactment',
                'a.kind',
                DB::raw('count(*) as tally'),
                /*
                 * The oldest request and the oldest decision per group, so the ages can be bucketed in PHP
                 * without reading every row. `min` is the oldest, which is the one worth reporting.
                 */
                DB::raw('min(a.created_at) as oldest_created_at'),
                DB::raw('min(a.decided_at) as oldest_decided_at'),
            ]);

        $pendingByAge = array_fill_keys(ApprovalAge::values(), 0);
        $outstandingByAge = array_fill_keys(ApprovalAge::values(), 0);
        $byKind = array_fill_keys(ApprovalKind::values(), 0);
        $pending = $pendingSpend = $approved = $declined = $withdrawn = 0;
        $outstanding = $confirmed = $abandoned = 0;
        $oldestPending = null;
        $oldestOutstanding = null;

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $tally = (int) ($values['tally'] ?? 0);
            $state = ApprovalState::tryFrom((string) ($values['state'] ?? '')) ?? ApprovalState::Pending;
            $enactment = EnactmentState::tryFrom((string) ($values['enactment'] ?? ''))
                ?? EnactmentState::NotRequired;
            $kind = ApprovalKind::tryFrom((string) ($values['kind'] ?? ''));

            match ($state) {
                ApprovalState::Pending => $pending += $tally,
                ApprovalState::Approved => $approved += $tally,
                ApprovalState::Declined => $declined += $tally,
                ApprovalState::Withdrawn => $withdrawn += $tally,
            };

            if ($state === ApprovalState::Pending) {
                if ($kind !== null) {
                    $byKind[$kind->value] += $tally;

                    if ($kind->touchesLiveSpend()) {
                        $pendingSpend += $tally;
                    }
                }

                $since = $this->date($values['oldest_created_at'] ?? null);

                if ($since !== null) {
                    $pendingByAge[ApprovalAge::of($since, $now)->value] += $tally;
                    $days = (int) $since->setTime(0, 0)->diff($now->setTime(0, 0))->days;
                    $oldestPending = $oldestPending === null ? $days : max($oldestPending, $days);
                }
            }

            if ($state === ApprovalState::Approved) {
                if ($enactment === EnactmentState::Confirmed) {
                    $confirmed += $tally;
                }

                if ($enactment === EnactmentState::Abandoned) {
                    $abandoned += $tally;
                }

                if ($enactment === EnactmentState::AwaitingHand) {
                    $outstanding += $tally;

                    $since = $this->date($values['oldest_decided_at'] ?? null);

                    if ($since !== null) {
                        $outstandingByAge[ApprovalAge::of($since, $now)->value] += $tally;
                        $days = (int) $since->setTime(0, 0)->diff($now->setTime(0, 0))->days;
                        $oldestOutstanding = $oldestOutstanding === null ? $days : max($oldestOutstanding, $days);
                    }
                }
            }
        }

        return new ApprovalLoad(
            pending: $pending,
            pendingTouchingSpend: $pendingSpend,
            approved: $approved,
            declined: $declined,
            withdrawn: $withdrawn,
            outstanding: $outstanding,
            confirmed: $confirmed,
            abandoned: $abandoned,
            // Null rather than zero with nothing waiting: "no backlog" and "a backlog of today" differ.
            oldestOutstandingDays: $oldestOutstanding,
            oldestPendingDays: $oldestPending,
            pendingByAge: $pendingByAge,
            outstandingByAge: $outstandingByAge,
            byKind: $byKind,
        );
    }

    /** @return list<ApprovalView> */
    private function list(Builder $builder, int $limit): array
    {
        $rows = $builder->limit($limit)->get();

        /** @var list<array<string, mixed>> $values */
        $values = [];

        foreach ($rows as $row) {
            $values[] = (array) $row;
        }

        return $this->views($values);
    }

    private function reread(string $workspaceId, string $requestId): ApprovalView
    {
        return $this->find($workspaceId, null, $requestId)
            ?? throw new Exception('The approval request disappeared while being written.');
    }

    /**
     * The filtered queue with the display joins, for the reads that return rows.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    private function filtered(string $workspaceId, ?array $visibleProjectIds, ApprovalFilter $filter): Builder
    {
        return $this->narrow($this->scope($workspaceId, $visibleProjectIds), $filter);
    }

    /**
     * Apply the filter to whichever builder was handed in.
     *
     * Separate from the joins because the aggregate read must not carry `a.*` in its select: Postgres refuses a
     * grouped query that also selects ungrouped columns, and the display joins exist only to name people.
     */
    private function narrow(Builder $builder, ApprovalFilter $filter): Builder
    {
        if ($filter->clientId !== null) {
            $builder->where('a.client_id', $filter->clientId);
        }

        if ($filter->projectId !== null) {
            $builder->where('a.project_id', $filter->projectId);
        }

        if ($filter->kind !== null) {
            $builder->where('a.kind', $filter->kind->value);
        }

        if ($filter->spendOnly) {
            /*
             * Derived from the enum rather than from a column. `touchesLiveSpend()` is the domain's statement
             * about each kind; a boolean column would be a copy of it that a migration could leave behind.
             */
            $builder->whereIn('a.kind', array_values(array_map(
                static fn (ApprovalKind $kind): string => $kind->value,
                array_filter(ApprovalKind::cases(), static fn (ApprovalKind $kind): bool => $kind->touchesLiveSpend()),
            )));
        }

        return $builder;
    }

    /**
     * The queue with the joins that name people, for the reads that return rows.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    private function scope(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        return $this->visible($workspaceId, $visibleProjectIds)
            ->leftJoin('clients as c', 'c.id', '=', 'a.client_id')
            ->leftJoin('projects as p', 'p.id', '=', 'a.project_id')
            ->leftJoin('users as r', 'r.id', '=', 'a.requested_by')
            ->leftJoin('users as d', 'd.id', '=', 'a.decided_by')
            ->leftJoin('users as e', 'e.id', '=', 'a.enacted_by')
            ->select([
                'a.*',
                'c.name as client_name',
                'p.name as project_name',
                'r.name as requested_by_name',
                'd.name as decided_by_name',
                'e.name as enacted_by_name',
            ]);
    }

    /**
     * Visibility, written the same way the task board writes it.
     *
     * A request on a visible project, or on a client the actor has a project with, or one belonging to neither —
     * the agency's own internal change, which has no client relationship to scope it by.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    private function visible(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        $builder = DB::table('approval_requests as a')->where('a.workspace_id', $workspaceId);

        if ($visibleProjectIds === null) {
            return $builder;
        }

        if ($visibleProjectIds === []) {
            return $builder->whereNull('a.project_id')->whereNull('a.client_id');
        }

        return $builder->where(function (Builder $scope) use ($workspaceId, $visibleProjectIds): void {
            $scope->whereIn('a.project_id', $visibleProjectIds)
                ->orWhere(function (Builder $byClient) use ($workspaceId, $visibleProjectIds): void {
                    $byClient->whereNull('a.project_id')
                        ->whereNotNull('a.client_id')
                        ->whereIn('a.client_id', function (Builder $sub) use ($workspaceId, $visibleProjectIds): void {
                            $sub->select('client_id')
                                ->from('projects')
                                ->where('workspace_id', $workspaceId)
                                ->whereIn('id', $visibleProjectIds);
                        });
                })
                ->orWhere(function (Builder $internal): void {
                    $internal->whereNull('a.project_id')->whereNull('a.client_id');
                });
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<ApprovalView>
     */
    private function views(array $rows): array
    {
        $subjects = $this->resolveSubjects($rows);
        $views = [];

        foreach ($rows as $values) {
            $type = TaskSubjectType::tryFrom((string) ($values['subject_type'] ?? ''));
            $subjectId = $this->text($values['subject_id'] ?? null);
            $subject = null;

            if ($type !== null && $subjectId !== null) {
                $subject = new TaskSubjectView(
                    type: $type,
                    id: $subjectId,
                    label: $subjects[$type->value][$subjectId] ?? null,
                    exists: array_key_exists($subjectId, $subjects[$type->value] ?? []),
                );
            }

            $views[] = new ApprovalView(
                id: (string) ($values['id'] ?? ''),
                kind: ApprovalKind::tryFrom((string) ($values['kind'] ?? '')) ?? ApprovalKind::SpendChange,
                title: (string) ($values['title'] ?? ''),
                rationale: (string) ($values['rationale'] ?? ''),
                proposal: $this->proposal($values['proposal'] ?? null),
                fingerprint: (string) ($values['proposal_fingerprint'] ?? ''),
                decidedFingerprint: $values['decided_fingerprint'] === null
                    ? null
                    : (string) $values['decided_fingerprint'],
                state: ApprovalState::tryFrom((string) ($values['state'] ?? '')) ?? ApprovalState::Pending,
                enactment: EnactmentState::tryFrom((string) ($values['enactment'] ?? ''))
                    ?? EnactmentState::NotRequired,
                clientId: $this->text($values['client_id'] ?? null),
                clientName: $this->text($values['client_name'] ?? null),
                projectId: $this->text($values['project_id'] ?? null),
                projectName: $this->text($values['project_name'] ?? null),
                requestedById: $this->text($values['requested_by'] ?? null),
                requestedByName: $this->text($values['requested_by_name'] ?? null),
                createdAt: $this->date($values['created_at'] ?? null) ?? new DateTimeImmutable('@0'),
                decidedById: $this->text($values['decided_by'] ?? null),
                decidedByName: $this->text($values['decided_by_name'] ?? null),
                decidedAt: $this->date($values['decided_at'] ?? null),
                decisionReason: $this->text($values['decision_reason'] ?? null),
                withdrawnReason: $this->text($values['withdrawn_reason'] ?? null),
                enactedByName: $this->text($values['enacted_by_name'] ?? null),
                enactedAt: $this->date($values['enacted_at'] ?? null),
                enactmentNote: $this->text($values['enactment_note'] ?? null),
                subject: $subject,
                supersedesId: $this->text($values['supersedes_id'] ?? null),
                origin: (string) ($values['origin'] ?? 'person'),
                agent: $this->text($values['agent'] ?? null),
                evidence: $this->proposal($values['evidence'] ?? null) ?: null,
                risk: $this->text($values['risk'] ?? null),
                expiresAt: $this->date($values['expires_at'] ?? null),
            );
        }

        return $views;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, ?string>>
     */
    private function resolveSubjects(array $rows): array
    {
        /** @var array<string, list<string>> $wanted */
        $wanted = [];

        foreach ($rows as $values) {
            $type = TaskSubjectType::tryFrom((string) ($values['subject_type'] ?? ''));
            $id = $this->text($values['subject_id'] ?? null);

            if ($type !== null && $id !== null) {
                $wanted[$type->value][] = $id;
            }
        }

        /* Only this workspace's own rows can name a subject: an id from another one resolves to "deleted". */
        $workspaces = array_values(array_unique(array_map(
            static fn (array $values): string => (string) ($values['workspace_id'] ?? ''),
            $rows,
        )));

        $resolved = [];

        foreach ($wanted as $typeValue => $ids) {
            $type = TaskSubjectType::from($typeValue);
            $labels = [];

            $found = DB::table($type->table())
                ->whereIn('workspace_id', $workspaces)
                ->whereIn('id', array_values(array_unique($ids)))
                ->get(['id', $type->labelColumn().' as label']);

            foreach ($found as $row) {
                /** @var array<string, mixed> $values */
                $values = (array) $row;
                $labels[(string) ($values['id'] ?? '')] = $this->text($values['label'] ?? null);
            }

            $resolved[$typeValue] = $labels;
        }

        return $resolved;
    }

    /** @return array<string, mixed> */
    private function proposal(mixed $value): array
    {
        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Exception) {
            return [];
        }

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
