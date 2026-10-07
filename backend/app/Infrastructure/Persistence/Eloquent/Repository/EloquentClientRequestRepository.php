<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Operations\Contract\ClientRequestFilter;
use App\Application\Operations\Contract\ClientRequestRepository;
use App\Application\Operations\Contract\TaskRepository;
use App\Application\Operations\DTO\ClientRequestInput;
use App\Application\Operations\DTO\ClientRequestMessageView;
use App\Application\Operations\DTO\ClientRequestView;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Operations\ClientRequestKind;
use App\Domain\Operations\ClientRequestState;
use App\Domain\Operations\IntakeLoad;
use App\Domain\Operations\MessageAudience;
use App\Domain\Operations\StatedUrgency;
use App\Domain\Operations\TaskSubjectType;
use DateTimeImmutable;
use Exception;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Client requests and the conversation on them.
 *
 * The decision that matters here is how `$clientFacing` is honoured: **internal messages are excluded in the
 * WHERE clause, never filtered after loading.** A note an agency wrote about a client must not be selected for
 * that client at all — a row that was selected has been hydrated, serialised into a DTO, and is one careless
 * refactor away from a response. The CHECK on `client_request_messages` enforces the other half: a client's own
 * message is always client-visible, so an interface bug cannot hide somebody's words from themselves.
 *
 * `first_answered_at` is stamped by a **client-visible message from the agency**, once. Writing to each other is
 * not answering, and a figure that counted internal notes as answers would report an intake queue as responsive
 * while the client heard nothing.
 */
final readonly class EloquentClientRequestRepository implements ClientRequestRepository
{
    public function __construct(
        private IdentifierGenerator $identifiers,
        /*
         * The task repository, for the one thing a client request needs from it: which tasks were raised about
         * this request. Reading tasks through their own repository rather than joining the table keeps the
         * visibility rules for a task in one place.
         */
        private TaskRepository $tasks,
    ) {}

    public function submit(
        string $workspaceId,
        ClientRequestInput $input,
        ?string $actorId,
        bool $asClient,
    ): ClientRequestView {
        $id = $this->identifiers->generate();

        DB::table('client_requests')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'client_id' => $input->clientId,
            'project_id' => $input->projectId,
            'kind' => $input->kind->value,
            'title' => $input->title,
            'detail' => $input->detail,
            'state' => ClientRequestState::Submitted->value,
            'stated_urgency' => $input->urgency->value,
            'wanted_by' => $input->wantedBy?->format('Y-m-d'),
            'submitted_by' => $actorId,
            'state_changed_at' => now(),
            /*
             * A request an agency member raised on a client's behalf counts as already answered: the conversation
             * happened before the row existed, and leaving it unanswered would put a fictional client wait at the
             * top of the queue.
             */
            'first_answered_at' => $asClient ? null : now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->find($workspaceId, null, $id, false)
            ?? throw new Exception('The request was not written.');
    }

    public function find(
        string $workspaceId,
        ?array $visibleProjectIds,
        string $requestId,
        bool $clientFacing,
    ): ?ClientRequestView {
        $row = $this->scope($workspaceId, $visibleProjectIds)->where('r.id', $requestId)->first();

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $values */
        $values = (array) $row;

        return $this->view($workspaceId, $values, $clientFacing, true);
    }

    public function move(
        string $workspaceId,
        string $requestId,
        ClientRequestState $state,
        ?string $reason,
        DateTimeImmutable $at,
    ): ClientRequestView {
        $update = [
            'state' => $state->value,
            'state_changed_at' => $at,
            'updated_at' => now(),
            'delivered_at' => $state === ClientRequestState::Delivered ? $at : null,
        ];

        if ($state === ClientRequestState::Declined) {
            $update['declined_reason'] = $reason;
        }

        if ($state === ClientRequestState::NeedsInformation) {
            $update['needed_information'] = $reason;
        }

        /*
         * Any triage decision is an answer: accepting, declining or asking a question all mean the client has
         * heard something. Stamped once, with `coalesce`, so a later move does not reset the figure that
         * measures how long the first answer took.
         */
        $update['first_answered_at'] = DB::raw(
            'coalesce(first_answered_at, '.$this->literal($at->format('Y-m-d H:i:s')).')'
        );

        DB::table('client_requests')
            ->where('workspace_id', $workspaceId)
            ->where('id', $requestId)
            ->update($update);

        return $this->reread($workspaceId, $requestId);
    }

    public function promise(
        string $workspaceId,
        string $requestId,
        ?DateTimeImmutable $promisedAt,
        ?string $promisedBy,
    ): ClientRequestView {
        DB::table('client_requests')
            ->where('workspace_id', $workspaceId)
            ->where('id', $requestId)
            ->update([
                'promised_at' => $promisedAt?->format('Y-m-d'),
                // Both or neither. The CHECK says the same thing from below.
                'promised_by' => $promisedAt === null ? null : $promisedBy,
                'updated_at' => now(),
            ]);

        return $this->reread($workspaceId, $requestId);
    }

    public function assignOwner(string $workspaceId, string $requestId, ?string $ownerId): ClientRequestView
    {
        DB::table('client_requests')
            ->where('workspace_id', $workspaceId)
            ->where('id', $requestId)
            ->update(['owner_id' => $ownerId, 'updated_at' => now()]);

        return $this->reread($workspaceId, $requestId);
    }

    public function message(
        string $workspaceId,
        string $requestId,
        MessageAudience $audience,
        string $body,
        ?string $authorId,
        bool $isFromClient,
        DateTimeImmutable $at,
    ): ClientRequestView {
        DB::table('client_request_messages')->insert([
            'id' => $this->identifiers->generate(),
            'workspace_id' => $workspaceId,
            'client_request_id' => $requestId,
            'audience' => $audience->value,
            'body' => $body,
            'author_id' => $authorId,
            'is_from_client' => $isFromClient,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        if (! $isFromClient && $audience->isVisibleToClient()) {
            /*
             * The only kind of message that counts as answering: client-visible, and from the agency. An internal
             * note is the agency talking to itself, and a figure that counted it would report a responsive queue
             * while the client heard nothing.
             */
            DB::table('client_requests')
                ->where('workspace_id', $workspaceId)
                ->where('id', $requestId)
                ->update([
                    'first_answered_at' => DB::raw(
                        'coalesce(first_answered_at, '.$this->literal($at->format('Y-m-d H:i:s')).')'
                    ),
                    'updated_at' => now(),
                ]);
        }

        return $this->reread($workspaceId, $requestId);
    }

    public function board(
        string $workspaceId,
        ?array $visibleProjectIds,
        ClientRequestFilter $filter,
        int $limitPerColumn,
        bool $clientFacing,
    ): array {
        $columns = [];

        foreach (ClientRequestState::board() as $state) {
            $rows = $this->filtered($workspaceId, $visibleProjectIds, $filter)
                ->where('r.state', $state->value)
                /*
                 * Oldest first within a column. An intake queue sorted newest-first hides the request that has
                 * been waiting longest, which is the only row anybody needs to find.
                 */
                ->orderBy('r.created_at')
                ->limit($limitPerColumn)
                ->get();

            $views = [];

            foreach ($rows as $row) {
                /** @var array<string, mixed> $values */
                $values = (array) $row;
                // No messages or tasks on a card: a column of conversations is a column nobody can scan.
                $views[] = $this->view($workspaceId, $values, $clientFacing, false);
            }

            $columns[$state->value] = $views;
        }

        return $columns;
    }

    public function load(
        string $workspaceId,
        ?array $visibleProjectIds,
        ClientRequestFilter $filter,
        DateTimeImmutable $now,
    ): IntakeLoad {
        $rows = $this->narrow($this->visible($workspaceId, $visibleProjectIds), $filter)
            ->groupBy('r.state', 'r.kind', 'r.stated_urgency')
            ->get([
                'r.state',
                'r.kind',
                'r.stated_urgency',
                DB::raw('count(*) as tally'),
                DB::raw('count(r.promised_at) as promised'),
                DB::raw('min(r.created_at) as oldest_created_at'),
                DB::raw('count(case when r.first_answered_at is null then 1 end) as unanswered'),
                DB::raw(sprintf(
                    'count(case when r.promised_at is not null and r.promised_at < %s then 1 end) as past_promise',
                    $this->literal($now->format('Y-m-d')),
                )),
            ]);

        $byState = array_fill_keys(ClientRequestState::values(), 0);
        $byKind = array_fill_keys(ClientRequestKind::values(), 0);
        $byUrgency = array_fill_keys(StatedUrgency::values(), 0);
        $total = $open = $onAgency = $onClient = $delivered = $declined = 0;
        $promised = $unpromised = $overdue = $calledUrgent = 0;
        $oldestUnanswered = null;

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $tally = (int) ($values['tally'] ?? 0);
            $state = ClientRequestState::tryFrom((string) ($values['state'] ?? ''))
                ?? ClientRequestState::Submitted;
            $kind = ClientRequestKind::tryFrom((string) ($values['kind'] ?? ''));
            $urgency = StatedUrgency::tryFrom((string) ($values['stated_urgency'] ?? ''));

            $total += $tally;
            $byState[$state->value] += $tally;

            if ($state === ClientRequestState::Delivered) {
                $delivered += $tally;
            }

            if ($state === ClientRequestState::Declined) {
                $declined += $tally;
            }

            if (! $state->isOpen()) {
                continue;
            }

            $open += $tally;

            if ($kind !== null) {
                $byKind[$kind->value] += $tally;
            }

            if ($urgency !== null) {
                $byUrgency[$urgency->value] += $tally;

                if ($urgency === StatedUrgency::Urgent) {
                    $calledUrgent += $tally;
                }
            }

            if ($state->isOnTheAgency()) {
                $onAgency += $tally;
            }

            if ($state->isOnTheClient()) {
                $onClient += $tally;
            }

            /*
             * Promises are counted for accepted work only. An unanswered request has nothing to promise yet, and
             * counting it as unpromised would make the figure describe the triage backlog instead.
             */
            if ($state === ClientRequestState::Accepted || $state === ClientRequestState::InProgress) {
                $withDate = (int) ($values['promised'] ?? 0);
                $promised += $withDate;
                $unpromised += $tally - $withDate;
            }

            $overdue += (int) ($values['past_promise'] ?? 0);

            if ((int) ($values['unanswered'] ?? 0) > 0) {
                $since = $this->date($values['oldest_created_at'] ?? null);

                if ($since !== null) {
                    $days = (int) $since->setTime(0, 0)->diff($now->setTime(0, 0))->days;
                    $oldestUnanswered = $oldestUnanswered === null ? $days : max($oldestUnanswered, $days);
                }
            }
        }

        return new IntakeLoad(
            total: $total,
            open: $open,
            onTheAgency: $onAgency,
            onTheClient: $onClient,
            delivered: $delivered,
            declined: $declined,
            promised: $promised,
            unpromised: $unpromised,
            overdue: $overdue,
            calledUrgent: $calledUrgent,
            // Null rather than zero with nothing unanswered: "nobody waiting" and "waiting today" differ.
            oldestUnansweredDays: $oldestUnanswered,
            byState: $byState,
            byKind: $byKind,
            byUrgency: $byUrgency,
        );
    }

    private function reread(string $workspaceId, string $requestId): ClientRequestView
    {
        return $this->find($workspaceId, null, $requestId, false)
            ?? throw new Exception('The client request disappeared while being written.');
    }

    /** @param list<string>|null $visibleProjectIds */
    private function filtered(
        string $workspaceId,
        ?array $visibleProjectIds,
        ClientRequestFilter $filter,
    ): Builder {
        return $this->narrow($this->scope($workspaceId, $visibleProjectIds), $filter);
    }

    private function narrow(Builder $builder, ClientRequestFilter $filter): Builder
    {
        if ($filter->clientId !== null) {
            $builder->where('r.client_id', $filter->clientId);
        }

        if ($filter->kind !== null) {
            $builder->where('r.kind', $filter->kind->value);
        }

        if ($filter->onTheAgencyOnly) {
            /*
             * Derived from the enum rather than from a column. `isOnTheAgency()` is the domain's statement about
             * each state; a boolean column would be a copy a migration could leave behind.
             */
            $builder->whereIn('r.state', array_values(array_map(
                static fn (ClientRequestState $state): string => $state->value,
                array_filter(
                    ClientRequestState::cases(),
                    static fn (ClientRequestState $state): bool => $state->isOnTheAgency(),
                ),
            )));
        }

        return $builder;
    }

    /** @param list<string>|null $visibleProjectIds */
    private function scope(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        return $this->visible($workspaceId, $visibleProjectIds)
            ->join('clients as c', 'c.id', '=', 'r.client_id')
            ->leftJoin('projects as p', 'p.id', '=', 'r.project_id')
            ->leftJoin('users as s', 's.id', '=', 'r.submitted_by')
            ->leftJoin('users as o', 'o.id', '=', 'r.owner_id')
            ->leftJoin('users as pr', 'pr.id', '=', 'r.promised_by')
            ->select([
                'r.*',
                'c.name as client_name',
                'p.name as project_name',
                's.name as submitted_by_name',
                'o.name as owner_name',
                'pr.name as promised_by_name',
            ]);
    }

    /**
     * Who may see which requests.
     *
     * The same rule the task board and the approval queue use, which is what makes a client's own view work with
     * no second rule to keep in step: a request on a visible project, or on a client the actor has any project
     * with. A client viewer assigned to their own project therefore sees their own requests and nobody else's.
     *
     * There is no "internal" case here, unlike tasks: a client request always has a client.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    private function visible(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        $builder = DB::table('client_requests as r')->where('r.workspace_id', $workspaceId);

        if ($visibleProjectIds === null) {
            return $builder;
        }

        if ($visibleProjectIds === []) {
            return $builder->whereRaw('1 = 0');
        }

        return $builder->where(function (Builder $scope) use ($workspaceId, $visibleProjectIds): void {
            $scope->whereIn('r.project_id', $visibleProjectIds)
                ->orWhereIn('r.client_id', function (Builder $sub) use ($workspaceId, $visibleProjectIds): void {
                    $sub->select('client_id')
                        ->from('projects')
                        ->where('workspace_id', $workspaceId)
                        ->whereIn('id', $visibleProjectIds);
                });
        });
    }

    /** @param array<string, mixed> $values */
    private function view(
        string $workspaceId,
        array $values,
        bool $clientFacing,
        bool $withConversation,
    ): ClientRequestView {
        $id = (string) ($values['id'] ?? '');

        return new ClientRequestView(
            id: $id,
            clientId: (string) ($values['client_id'] ?? ''),
            clientName: (string) ($values['client_name'] ?? ''),
            kind: ClientRequestKind::tryFrom((string) ($values['kind'] ?? '')) ?? ClientRequestKind::Other,
            title: (string) ($values['title'] ?? ''),
            detail: $this->text($values['detail'] ?? null),
            state: ClientRequestState::tryFrom((string) ($values['state'] ?? ''))
                ?? ClientRequestState::Submitted,
            urgency: StatedUrgency::tryFrom((string) ($values['stated_urgency'] ?? ''))
                ?? StatedUrgency::WheneverYouCan,
            projectId: $this->text($values['project_id'] ?? null),
            projectName: $this->text($values['project_name'] ?? null),
            wantedBy: $this->date($values['wanted_by'] ?? null),
            promisedAt: $this->date($values['promised_at'] ?? null),
            promisedByName: $this->text($values['promised_by_name'] ?? null),
            /*
             * Also withheld. A client knows who they are; being shown the name of the agency member who filed a
             * request on their behalf tells them only which of their own words were re-typed by somebody else.
             */
            submittedByName: $clientFacing ? null : $this->text($values['submitted_by_name'] ?? null),
            /*
             * Withheld from the client, both of them. Who inside an agency is handling something is the agency's
             * business — and it is the field a client would chase by name, which is a conversation the agency
             * should choose to have rather than have forced on it by a response shape.
             *
             * `promised_by_name` is deliberately *not* withheld: a promise was made **to** the client, so who
             * made it is part of the promise rather than an internal detail.
             */
            ownerId: $clientFacing ? null : $this->text($values['owner_id'] ?? null),
            ownerName: $clientFacing ? null : $this->text($values['owner_name'] ?? null),
            declinedReason: $this->text($values['declined_reason'] ?? null),
            neededInformation: $this->text($values['needed_information'] ?? null),
            firstAnsweredAt: $this->date($values['first_answered_at'] ?? null),
            deliveredAt: $this->date($values['delivered_at'] ?? null),
            stateChangedAt: $this->date($values['state_changed_at'] ?? null),
            createdAt: $this->date($values['created_at'] ?? null) ?? new DateTimeImmutable('@0'),
            messages: $withConversation ? $this->messages($workspaceId, $id, $clientFacing) : [],
            /*
             * Only on the agency's side. The tasks raised from a request are the agency's own plan of work, with
             * its own assignees and estimates, and showing them to a client is the same leak an internal note is.
             */
            tasks: $withConversation && ! $clientFacing ? $this->spawnedTasks($workspaceId, $id) : [],
        );
    }

    /** @return list<ClientRequestMessageView> */
    private function messages(string $workspaceId, string $requestId, bool $clientFacing): array
    {
        $builder = DB::table('client_request_messages as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.author_id')
            ->where('m.workspace_id', $workspaceId)
            ->where('m.client_request_id', $requestId);

        if ($clientFacing) {
            /*
             * Excluded here, in the WHERE clause. See the class comment: a note an agency wrote about a client
             * must not be selected for that client at all.
             */
            $builder->where('m.audience', MessageAudience::Client->value);
        }

        $rows = $builder->orderBy('m.created_at')->get(['m.*', 'u.name as author_name']);
        $messages = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $messages[] = new ClientRequestMessageView(
                id: (string) ($values['id'] ?? ''),
                audience: MessageAudience::tryFrom((string) ($values['audience'] ?? ''))
                    // The safe default for a value that somehow does not parse. Never the client.
                    ?? MessageAudience::Internal,
                body: (string) ($values['body'] ?? ''),
                authorName: $this->text($values['author_name'] ?? null),
                isFromClient: (bool) ($values['is_from_client'] ?? false),
                createdAt: $this->date($values['created_at'] ?? null) ?? new DateTimeImmutable('@0'),
            );
        }

        return $messages;
    }

    /**
     * Tasks raised about this request.
     *
     * Read through the task repository rather than by joining the table, so the visibility rules for a task stay
     * in one place. `null` for visible projects because the caller has already established that this request is
     * visible, and a task about it is part of the agency's answer to it.
     *
     * @return list<\App\Application\Operations\DTO\TaskView>
     */
    private function spawnedTasks(string $workspaceId, string $requestId): array
    {
        return $this->tasks->forSubject(
            $workspaceId,
            null,
            TaskSubjectType::ClientRequest,
            $requestId,
            50,
        );
    }

    /**
     * A date or timestamp as a SQL literal, for the two updates that coalesce rather than overwrite.
     *
     * Only ever called with a value this code formatted itself — a clock reading — never with anything a caller
     * supplied.
     */
    private function literal(string $value): string
    {
        return "'".$value."'";
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
