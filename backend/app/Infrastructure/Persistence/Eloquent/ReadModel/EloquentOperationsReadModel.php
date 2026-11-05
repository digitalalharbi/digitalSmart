<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Integration\Contract\OperationsReadModel;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

final readonly class EloquentOperationsReadModel implements OperationsReadModel
{
    public function __construct(private QueueFactory $queues) {}

    public function runs(string $workspaceId, DateTimeImmutable $since, int $failureLimit): array
    {
        /** @var list<stdClass> $perProvider */
        $perProvider = DB::table('sync_runs')
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $since->format('Y-m-d H:i:s'))
            ->selectRaw("provider, count(*) as runs, count(*) filter (where status = 'failed') as failed,
                count(*) filter (where status = 'partial') as partial")
            ->groupBy('provider')->orderBy('provider')->get()->all();

        /** @var list<stdClass> $failures */
        $failures = DB::table('sync_runs as r')
            ->leftJoin('external_accounts as a', 'a.id', '=', 'r.external_account_id')
            ->where('r.workspace_id', $workspaceId)
            ->whereIn('r.status', ['failed', 'partial'])
            ->where('r.created_at', '>=', $since->format('Y-m-d H:i:s'))
            ->orderByDesc('r.created_at')
            ->limit($failureLimit)
            ->get(['r.external_account_id', 'a.name as account_name', 'r.provider', 'r.kind', 'r.error_code', 'r.error_message', 'r.created_at'])
            ->all();

        $last = DB::table('sync_runs')->where('workspace_id', $workspaceId)->max('finished_at');

        return [
            'providers' => array_map(static fn (stdClass $r): array => [
                'provider' => (string) $r->provider, 'runs' => (int) $r->runs,
                'failed' => (int) $r->failed, 'partial' => (int) $r->partial,
            ], $perProvider),
            'failures' => array_map(static fn (stdClass $r): array => [
                'account_id' => (string) $r->external_account_id,
                'account_name' => (string) ($r->account_name ?? ''),
                'provider' => (string) $r->provider,
                'kind' => (string) $r->kind,
                'code' => is_string($r->error_code) ? $r->error_code : null,
                'message' => is_string($r->error_message) ? $r->error_message : null,
                'at' => (string) $r->created_at,
            ], $failures),
            'last_finished_at' => is_string($last) ? $last : null,
        ];
    }

    public function connectionHistory(string $workspaceId): array
    {
        /*
         * One grouped statement however many connections there are. The latest row per connection is picked with
         * `distinct on` rather than a correlated sub-select per connection, and settled runs only: a run still
         * pending or running is not yet an attempt with an outcome.
         */
        /** @var list<stdClass> $rows */
        $rows = DB::select(
            <<<'SQL'
            with settled as (
                select provider_connection_id, provider, status, error_code, coalesce(finished_at, created_at) as at
                from sync_runs
                where workspace_id = ? and provider_connection_id is not null
                  and status in ('succeeded', 'partial', 'failed')
            ),
            latest as (
                select distinct on (provider_connection_id) provider_connection_id, provider, status, error_code, at
                from settled order by provider_connection_id, at desc
            ),
            totals as (
                select provider_connection_id,
                    max(at) filter (where status = 'succeeded') as last_success_at,
                    max(at) filter (where status = 'failed') as last_failure_at
                from settled group by provider_connection_id
            )
            select l.provider_connection_id, l.provider, l.status, l.at, t.last_success_at, t.last_failure_at,
                (select e.error_code from settled e where e.provider_connection_id = l.provider_connection_id
                    and e.status = 'failed' order by e.at desc limit 1) as last_error_code,
                (select count(*) from settled f where f.provider_connection_id = l.provider_connection_id
                    and f.status = 'failed' and (t.last_success_at is null or f.at > t.last_success_at)) as failures_since_success
            from latest l join totals t on t.provider_connection_id = l.provider_connection_id
            order by l.provider, l.provider_connection_id
            SQL,
            [$workspaceId],
        );

        return array_map(static fn (stdClass $r): array => [
            'connection_id' => (string) $r->provider_connection_id,
            'provider' => (string) $r->provider,
            'last_attempt_at' => (string) $r->at,
            'last_attempt_status' => (string) $r->status,
            'last_success_at' => is_string($r->last_success_at) ? $r->last_success_at : null,
            'last_failure_at' => is_string($r->last_failure_at) ? $r->last_failure_at : null,
            'last_error_code' => is_string($r->last_error_code) ? $r->last_error_code : null,
            'failures_since_success' => (int) $r->failures_since_success,
        ], $rows);
    }

    /**
     * @param  list<string>  $queues
     * @return array<string, int>
     */
    public function queueDepth(array $queues): array
    {
        $depth = [];

        foreach ($queues as $queue) {
            try {
                $depth[(string) $queue] = $this->queues->connection()->size((string) $queue);
            } catch (Throwable) {
                /* A queue that cannot be sized is reported as unknown by being absent, never as empty. */
            }
        }

        return $depth;
    }
}
