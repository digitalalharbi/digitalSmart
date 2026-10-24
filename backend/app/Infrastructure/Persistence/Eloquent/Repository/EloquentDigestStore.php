<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Digest\Contract\DigestStore;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Attention\DigestKind;
use App\Infrastructure\Saas\RunningWorkspaces;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class EloquentDigestStore implements DigestStore
{
    public function __construct(private IdentifierGenerator $ids) {}

    /** @return list<array{user_id: string, workspace_id: string}> */
    public function recipients(?string $workspaceId): array
    {
        $out = [];

        foreach (DB::table('workspace_members')->where('status', 'active')
            ->when($workspaceId !== null, static fn ($q) => $q->where('workspace_id', $workspaceId))
            ->orderBy('workspace_id')->orderBy('user_id')->get(['user_id', 'workspace_id']) as $m) {
            $out[] = ['user_id' => (string) $m->user_id, 'workspace_id' => (string) $m->workspace_id];
        }

        return $out;
    }

    /** @return list<array{workspace_id: string, timezone: string}> */
    public function workspaces(?string $workspaceId): array
    {
        $out = [];

        foreach (RunningWorkspaces::only(DB::table('workspaces'), 'workspaces.id')
            ->when($workspaceId !== null, static fn ($q) => $q->where('id', $workspaceId))
            ->orderBy('id')->get(['id', 'timezone']) as $w) {
            $timezone = is_string($w->timezone) && $w->timezone !== '' ? $w->timezone : 'UTC';
            $out[] = ['workspace_id' => (string) $w->id, 'timezone' => $timezone];
        }

        return $out;
    }

    public function exists(string $workspaceId, DigestKind $kind, DateTimeImmutable $on): bool
    {
        return DB::table('digests')
            ->where(['workspace_id' => $workspaceId, 'kind' => $kind->value, 'period_on' => $on->format('Y-m-d')])
            ->exists();
    }

    public function existsFor(string $workspaceId, string $userId, DigestKind $kind, DateTimeImmutable $on): bool
    {
        return DB::table('digests')
            ->where(['workspace_id' => $workspaceId, 'user_id' => $userId, 'kind' => $kind->value, 'period_on' => $on->format('Y-m-d')])
            ->exists();
    }

    public function put(string $workspaceId, string $userId, DigestKind $kind, DateTimeImmutable $on, int $total, array $summary): ?string
    {
        $key = ['workspace_id' => $workspaceId, 'user_id' => $userId, 'kind' => $kind->value, 'period_on' => $on->format('Y-m-d')];
        $existed = DB::table('digests')->where($key)->exists();
        $id = $this->ids->generate();

        DB::table('digests')->upsert(
            [[
                'id' => $id, 'workspace_id' => $workspaceId, 'user_id' => $userId,
                'kind' => $kind->value, 'period_on' => $on->format('Y-m-d'), 'total' => $total,
                'summary' => json_encode($summary, JSON_THROW_ON_ERROR), 'created_at' => $on, 'updated_at' => $on,
            ]],
            ['user_id', 'workspace_id', 'kind', 'period_on'],
            ['total', 'summary', 'updated_at'],
        );

        return $existed ? null : $id;
    }

    /** @return list<array{id: string, kind: string, period_on: string, total: int, summary: array<string, mixed>, read_at: ?string}> */
    public function latest(string $workspaceId, string $userId, int $limit): array
    {
        $out = [];

        foreach (DB::table('digests')->where(['workspace_id' => $workspaceId, 'user_id' => $userId])
            ->orderByDesc('period_on')->orderByDesc('created_at')->limit($limit)->get() as $d) {
            /** @var array<string, mixed> $summary */
            $summary = json_decode((string) $d->summary, true) ?: [];
            $out[] = [
                'id' => (string) $d->id, 'kind' => (string) $d->kind, 'period_on' => substr((string) $d->period_on, 0, 10),
                'total' => (int) $d->total, 'summary' => $summary,
                'read_at' => is_string($d->read_at) ? $d->read_at : null,
            ];
        }

        return $out;
    }

    public function markRead(string $workspaceId, string $userId, string $digestId, DateTimeImmutable $at): void
    {
        DB::table('digests')->where(['workspace_id' => $workspaceId, 'user_id' => $userId, 'id' => $digestId])
            ->whereNull('read_at')->update(['read_at' => $at]);
    }
}
