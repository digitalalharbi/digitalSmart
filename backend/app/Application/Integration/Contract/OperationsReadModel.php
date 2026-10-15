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
}
