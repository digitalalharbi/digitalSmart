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
