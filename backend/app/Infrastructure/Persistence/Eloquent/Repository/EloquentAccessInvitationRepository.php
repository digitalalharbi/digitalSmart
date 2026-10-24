<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Access\Contract\AccessInvitationRepository;
use App\Application\Access\DTO\InvitationView;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class EloquentAccessInvitationRepository implements AccessInvitationRepository
{
    public function create(
        string $id,
        string $workspaceId,
        string $requestId,
        string $tokenHash,
        string $tokenHint,
        string $email,
        string $name,
        string $roleId,
        array $projectIds,
        ?string $createdBy,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): void {
        $stamp = $at->format('Y-m-d H:i:sP');

        DB::table('access_invitations')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'access_request_id' => $requestId,
            'token_hash' => $tokenHash,
            'token_hint' => $tokenHint,
            'email' => $email,
            'name' => $name,
            'role_id' => $roleId,
            'project_ids' => json_encode($projectIds, JSON_THROW_ON_ERROR),
            'created_by' => $createdBy,
            'expires_at' => $expiresAt->format('Y-m-d H:i:sP'),
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);
    }

    public function revokeOpen(string $workspaceId, string $requestId, DateTimeImmutable $at): void
    {
        DB::table('access_invitations')
            ->where('workspace_id', $workspaceId)->where('access_request_id', $requestId)
            ->whereNull('accepted_at')->whereNull('revoked_at')
            ->update(['revoked_at' => $at->format('Y-m-d H:i:sP')]);
    }

    public function findByTokenHash(string $tokenHash): ?InvitationView
    {
        $row = $this->base()->where('i.token_hash', $tokenHash)->first();

        return $row instanceof stdClass ? $this->view($row) : null;
    }

    public function latestFor(string $workspaceId, string $requestId): ?InvitationView
    {
        $row = $this->base()
            ->where('i.workspace_id', $workspaceId)->where('i.access_request_id', $requestId)
            ->orderByDesc('i.created_at')->orderByDesc('i.id')->first();

        return $row instanceof stdClass ? $this->view($row) : null;
    }

    public function markAccepted(string $id, DateTimeImmutable $at): void
    {
        DB::table('access_invitations')->where('id', $id)->update(['accepted_at' => $at->format('Y-m-d H:i:sP')]);
    }

    /** @return array{made: int, accepted: int} */
    public function acceptance(string $workspaceId, DateTimeImmutable $since): array
    {
        $row = DB::table('access_invitations')
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $since->format('Y-m-d H:i:sP'))
            ->selectRaw('count(*) as made, count(accepted_at) as accepted')
            ->first();

        return [
            'made' => $row instanceof stdClass ? (int) $row->made : 0,
            'accepted' => $row instanceof stdClass ? (int) $row->accepted : 0,
        ];
    }

    private function base(): \Illuminate\Database\Query\Builder
    {
        return DB::table('access_invitations as i')
            ->join('roles as ro', 'ro.id', '=', 'i.role_id')
            ->select('i.*', 'ro.slug as role_slug');
    }

    private function view(stdClass $row): InvitationView
    {
        $projects = json_decode((string) $row->project_ids, true);

        return new InvitationView(
            id: (string) $row->id,
            workspaceId: (string) $row->workspace_id,
            accessRequestId: (string) $row->access_request_id,
            tokenHint: (string) $row->token_hint,
            email: (string) $row->email,
            name: (string) $row->name,
            roleId: (string) $row->role_id,
            roleSlug: (string) $row->role_slug,
            projectIds: is_array($projects) ? array_values(array_map('strval', $projects)) : [],
            createdBy: $row->created_by === null ? null : (string) $row->created_by,
            expiresAt: new DateTimeImmutable((string) $row->expires_at),
            acceptedAt: $row->accepted_at === null ? null : new DateTimeImmutable((string) $row->accepted_at),
            revokedAt: $row->revoked_at === null ? null : new DateTimeImmutable((string) $row->revoked_at),
            createdAt: new DateTimeImmutable((string) $row->created_at),
        );
    }
}
