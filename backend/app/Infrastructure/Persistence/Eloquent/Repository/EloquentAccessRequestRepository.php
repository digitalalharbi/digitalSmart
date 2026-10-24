<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Access\Contract\AccessRequestRepository;
use App\Application\Access\DTO\AccessEventView;
use App\Application\Access\DTO\AccessRequestView;
use App\Application\Access\DTO\AccessSettings;
use App\Application\Access\DTO\NewAccessRequest;
use App\Domain\Access\AccessEventType;
use App\Domain\Access\AccessRequestKind;
use App\Domain\Access\AccessRequestNeed;
use App\Domain\Access\AccessRequestStatus;
use App\Domain\Access\PreferredContact;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
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

    public function likelyDuplicate(string $workspaceId, string $email, ?string $phoneKey, DateTimeImmutable $since): ?string
    {
        $query = DB::table('access_requests')
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $since->format('Y-m-d H:i:sP'))
            ->where(function (Builder $q) use ($email, $phoneKey): void {
                $q->where('email', $email);

                if ($phoneKey !== null) {
                    $q->orWhere('phone_key', $phoneKey);
                }
            })
            /* The earliest of a run is the one the others are linked to, so a third request does not make a chain. */
            ->orderByRaw('(possible_duplicate_of IS NOT NULL)')
            ->orderBy('created_at')
            ->orderBy('id');

        $id = $query->value('id');

        return is_string($id) ? $id : null;
    }

    public function add(NewAccessRequest $new): void
    {
        $at = $new->at->format('Y-m-d H:i:sP');

        DB::table('access_requests')->insert([
            'id' => $new->id,
            'workspace_id' => $new->workspaceId,
            'kind' => $new->kind->value,
            'name' => $new->name,
            'email' => $new->email,
            'company' => $new->company,
            'phone' => $new->phone,
            'phone_key' => $new->phoneKey,
            'job_title' => $new->jobTitle,
            'preferred_contact' => $new->preferredContact->value,
            'need' => $new->need?->value,
            'message' => $new->message,
            'locale' => $new->locale,
            'status' => AccessRequestStatus::New->value,
            'owner_id' => $new->ownerId,
            'possible_duplicate_of' => $new->possibleDuplicateOf,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        if ($new->possibleDuplicateOf !== null) {
            DB::table('access_request_events')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid7(),
                'workspace_id' => $new->workspaceId,
                'access_request_id' => $new->id,
                'type' => AccessEventType::DuplicateLinked->value,
                'meta' => json_encode(['of' => $new->possibleDuplicateOf], JSON_THROW_ON_ERROR),
                'occurred_at' => $at,
            ]);
        }
    }

    public function find(string $workspaceId, string $id): ?AccessRequestView
    {
        $row = $this->base()->where('r.workspace_id', $workspaceId)->where('r.id', $id)->first();

        return $row instanceof stdClass ? $this->view($row) : null;
    }

    public function list(string $workspaceId, ?array $statuses, ?string $ownerId, int $limit): array
    {
        $query = $this->base()->where('r.workspace_id', $workspaceId);

        if ($statuses !== null) {
            $query->whereIn('r.status', array_map(static fn (AccessRequestStatus $s): string => $s->value, $statuses));
        }

        if ($ownerId !== null) {
            $query->where('r.owner_id', $ownerId);
        }

        return $this->views($query->orderByDesc('r.created_at')->orderByDesc('r.id')->limit($limit)->get()->all());
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

    public function update(string $workspaceId, string $id, array $fields, DateTimeImmutable $at): void
    {
        DB::table('access_requests')->where('workspace_id', $workspaceId)->where('id', $id)->update([
            ...$fields,
            'updated_at' => $at->format('Y-m-d H:i:sP'),
        ]);
    }

    public function addEvent(
        string $id,
        string $workspaceId,
        string $requestId,
        AccessEventType $type,
        ?string $actorId,
        ?string $body,
        ?array $meta,
        DateTimeImmutable $at,
    ): void {
        DB::table('access_request_events')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'access_request_id' => $requestId,
            'type' => $type->value,
            'actor_id' => $actorId,
            'body' => $body,
            'meta' => $meta === null ? null : json_encode($meta, JSON_THROW_ON_ERROR),
            'occurred_at' => $at->format('Y-m-d H:i:sP'),
        ]);
    }

    public function events(string $workspaceId, string $requestId): array
    {
        $rows = DB::table('access_request_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_id')
            ->where('e.workspace_id', $workspaceId)
            ->where('e.access_request_id', $requestId)
            ->orderBy('e.occurred_at')->orderBy('e.id')
            ->select('e.*', 'u.name as actor_name')
            ->get();

        $events = [];

        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }

            $meta = $row->meta === null ? null : json_decode((string) $row->meta, true);
            $events[] = new AccessEventView(
                id: (string) $row->id,
                type: AccessEventType::from((string) $row->type),
                actorName: $row->actor_name === null ? null : (string) $row->actor_name,
                body: $row->body === null ? null : (string) $row->body,
                meta: is_array($meta) ? $meta : null,
                occurredAt: new DateTimeImmutable((string) $row->occurred_at),
            );
        }

        return $events;
    }

    public function linked(string $workspaceId, string $requestId): array
    {
        $own = DB::table('access_requests')->where('workspace_id', $workspaceId)->where('id', $requestId)->value('possible_duplicate_of');

        $query = $this->base()->where('r.workspace_id', $workspaceId)->where('r.id', '<>', $requestId)->where(function (Builder $q) use ($requestId, $own): void {
            $q->where('r.possible_duplicate_of', $requestId);

            if (is_string($own)) {
                $q->orWhere('r.id', $own)->orWhere('r.possible_duplicate_of', $own);
            }
        });

        return $this->views($query->orderBy('r.created_at')->get()->all());
    }

    public function settings(string $workspaceId): AccessSettings
    {
        $row = DB::table('access_request_settings as s')
            ->leftJoin('users as u', 'u.id', '=', 's.default_owner_id')
            ->where('s.workspace_id', $workspaceId)
            ->select('s.response_minutes', 's.default_owner_id', 'u.name as owner_name')
            ->first();

        if (! $row instanceof stdClass) {
            return new AccessSettings(AccessSettings::DEFAULT_MINUTES, null, null);
        }

        return new AccessSettings(
            (int) $row->response_minutes,
            $row->default_owner_id === null ? null : (string) $row->default_owner_id,
            $row->owner_name === null ? null : (string) $row->owner_name,
        );
    }

    public function saveSettings(string $workspaceId, int $responseMinutes, ?string $defaultOwnerId, DateTimeImmutable $at): void
    {
        DB::table('access_request_settings')->updateOrInsert(
            ['workspace_id' => $workspaceId],
            ['response_minutes' => $responseMinutes, 'default_owner_id' => $defaultOwnerId, 'updated_at' => $at->format('Y-m-d H:i:sP')],
        );
    }

    public function awaitingFirstAttempt(DateTimeImmutable $since): array
    {
        $rows = $this->base()
            ->where('r.status', AccessRequestStatus::New->value)
            ->whereNull('r.first_attempt_at')
            ->where('r.created_at', '>=', $since->format('Y-m-d H:i:sP'))
            ->leftJoin('access_request_settings as s', 's.workspace_id', '=', 'r.workspace_id')
            ->addSelect(DB::raw('coalesce(s.response_minutes, '.AccessSettings::DEFAULT_MINUTES.') as sla_minutes'))
            ->orderBy('r.created_at')->get();

        $out = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $out[] = ['view' => $this->view($row), 'minutes' => (int) $row->sla_minutes];
            }
        }

        return $out;
    }

    public function invitationsPending(DateTimeImmutable $before): array
    {
        return $this->views($this->base()
            ->where('r.status', AccessRequestStatus::Approved->value)
            ->whereNotNull('r.invited_at')
            ->whereNull('r.accepted_at')
            ->where('r.invited_at', '<', $before->format('Y-m-d H:i:sP'))
            ->whereExists(static function (Builder $q): void {
                $q->selectRaw('1')->from('access_invitations as i')
                    ->whereColumn('i.access_request_id', 'r.id')
                    ->whereNull('i.accepted_at')->whereNull('i.revoked_at')
                    ->where('i.expires_at', '>', now());
            })
            ->orderBy('r.invited_at')->get()->all());
    }

    public function recordFirstLogin(string $userId, DateTimeImmutable $at): bool
    {
        $stamp = $at->format('Y-m-d H:i:sP');
        $row = DB::table('access_requests')->where('account_user_id', $userId)->whereNull('first_login_at')->first(['id', 'workspace_id']);

        if (! $row instanceof stdClass) {
            return false;
        }

        $changed = DB::table('access_requests')->where('id', $row->id)->whereNull('first_login_at')->update(['first_login_at' => $stamp, 'updated_at' => $stamp]);

        if ($changed === 0) {
            return false;
        }

        DB::table('access_request_events')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'workspace_id' => $row->workspace_id,
            'access_request_id' => $row->id,
            'type' => AccessEventType::FirstLogin->value,
            'actor_id' => $userId,
            'occurred_at' => $stamp,
        ]);

        return true;
    }

    public function createdSince(string $workspaceId, DateTimeImmutable $since, int $limit): array
    {
        return $this->views($this->base()
            ->where('r.workspace_id', $workspaceId)
            ->where('r.created_at', '>=', $since->format('Y-m-d H:i:sP'))
            ->orderBy('r.created_at')->limit($limit)->get()->all());
    }

    private function base(): Builder
    {
        return DB::table('access_requests as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.reviewed_by')
            ->leftJoin('users as o', 'o.id', '=', 'r.owner_id')
            ->select('r.*', 'u.name as reviewed_by_name', 'o.name as owner_name');
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<AccessRequestView>
     */
    private function views(array $rows): array
    {
        $views = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $views[] = $this->view($row);
            }
        }

        return $views;
    }

    private function view(stdClass $row): AccessRequestView
    {
        return new AccessRequestView(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            kind: AccessRequestKind::from((string) $row->kind),
            name: (string) $row->name,
            email: (string) $row->email,
            company: self::text($row->company),
            phone: self::text($row->phone),
            jobTitle: self::text($row->job_title),
            preferredContact: PreferredContact::from((string) $row->preferred_contact),
            need: $row->need === null ? null : AccessRequestNeed::from((string) $row->need),
            message: self::text($row->message),
            locale: (string) $row->locale,
            status: AccessRequestStatus::from((string) $row->status),
            createdAt: new DateTimeImmutable((string) $row->created_at),
            ownerId: self::text($row->owner_id),
            ownerName: self::text($row->owner_name),
            firstAttemptAt: self::time($row->first_attempt_at),
            firstContactedAt: self::time($row->first_contacted_at),
            lastContactAt: self::time($row->last_contact_at),
            nextFollowUpAt: self::time($row->next_follow_up_at),
            possibleDuplicateOf: self::text($row->possible_duplicate_of),
            reviewedAt: self::time($row->reviewed_at),
            reviewedByName: self::text($row->reviewed_by_name),
            reviewNote: self::text($row->review_note),
            clientMessage: self::text($row->client_message),
            invitedAt: self::time($row->invited_at),
            invitationSharedAt: self::time($row->invitation_shared_at),
            invitationOpenedAt: self::time($row->invitation_opened_at),
            acceptedAt: self::time($row->accepted_at),
            accountUserId: self::text($row->account_user_id),
            firstLoginAt: self::time($row->first_login_at),
        );
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function time(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable((string) $value);
    }
}
