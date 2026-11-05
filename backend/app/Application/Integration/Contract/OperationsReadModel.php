<?php

declare(strict_types=1);

namespace App\Application\Integration\Contract;

use DateTimeImmutable;

interface OperationsReadModel
{
    /**
     * Sync runs per provider since a moment, and the most recent failures.
     *
     * @return array{
     *     providers: list<array{provider: string, runs: int, failed: int, partial: int}>,
     *     failures: list<array{account_id: string, account_name: string, provider: string, kind: string, code: ?string, message: ?string, at: string}>,
     *     last_finished_at: ?string
     * }
     */
    public function runs(string $workspaceId, DateTimeImmutable $since, int $failureLimit): array;

    /**
     * @param  list<string>  $queues
     * @return array<string, int> jobs waiting, per queue name, for the queues asked about
     */
    public function queueDepth(array $queues): array;

    /**
     * Each connection's last attempt beside its last success — two different facts.
     *
     * "Last synced" over a connection that has failed every hour since Tuesday would name this morning; the attempt is recent and the
     * success is not, and only showing both says so. A connection that has never been read is absent rather than given dates.
     *
     * @return list<array{connection_id: string, provider: string, last_attempt_at: string, last_attempt_status: string, last_success_at: ?string, last_failure_at: ?string, last_error_code: ?string, failures_since_success: int}>
     */
    public function connectionHistory(string $workspaceId): array;
}
