<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Application\Shared\Contract\WorkLedger;
use App\Application\Shared\DTO\WorkBacklog;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;

/**
 * Reads the queue's own tables.
 *
 * Works for the `database` driver, which stores `available_at` as a Unix timestamp, and for the
 * `database-uuids` failed-job store, which stores `failed_at` as a timestamp. Every other driver is answered
 * honestly rather than approximately: Redis keeps a list whose members are serialised payloads, and reading an
 * age out of it means deserialising the head of each queue — so this says the age is unknown instead, and the
 * probe publishes that it could not look.
 */
final readonly class DatabaseWorkLedger implements WorkLedger
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private QueueFactory $queue,
        private ConfigRepository $config,
    ) {}

    public function backlog(): WorkBacklog
    {
        if ($this->driver() !== 'database') {
            return WorkBacklog::countOnly($this->queueDepth());
        }

        $row = $this->table('jobs')
            ->selectRaw('count(*) as pending, min(available_at) as oldest')
            ->first();

        $pending = is_object($row) && isset($row->pending) ? (int) $row->pending : 0;
        $oldest = is_object($row) && isset($row->oldest) ? (int) $row->oldest : null;

        return WorkBacklog::of(
            $pending,
            $oldest === null || $pending === 0 ? null : new DateTimeImmutable('@'.$oldest),
        );
    }

    public function failures(): WorkBacklog
    {
        /*
         * The failed store is configured separately from the queue connection, and `null` is a legitimate
         * setting — an environment that keeps no failed jobs has nothing to report rather than nothing wrong.
         */
        $driver = $this->config->get('queue.failed.driver');

        if (! in_array($driver, ['database', 'database-uuids'], true)) {
            return WorkBacklog::countOnly(0);
        }

        $table = $this->config->get('queue.failed.table');
        $row = $this->table(is_string($table) ? $table : 'failed_jobs')
            ->selectRaw('count(*) as failures, min(failed_at) as oldest')
            ->first();

        $failures = is_object($row) && isset($row->failures) ? (int) $row->failures : 0;
        $oldest = is_object($row) && isset($row->oldest) && is_string($row->oldest) ? $row->oldest : null;

        return WorkBacklog::of(
            $failures,
            $oldest === null || $failures === 0 ? null : new DateTimeImmutable($oldest),
        );
    }

    private function queueDepth(): int
    {
        /*
         * Asked of the driver rather than of a table. `size()` is the only depth a non-database driver
         * publishes, and it is a count with no age attached — which is exactly what `countOnly` means.
         */
        return $this->queue->connection()->size();
    }

    private function driver(): string
    {
        $connection = $this->config->get('queue.default');
        $driver = $this->config->get('queue.connections.'.(is_string($connection) ? $connection : '').'.driver');

        return is_string($driver) ? $driver : 'unknown';
    }

    private function table(string $table): Builder
    {
        $database = $this->config->get('queue.failed.database');

        return $this->connections->connection(is_string($database) ? $database : null)->table($table);
    }
}
