<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Application\Shared\Contract\HealthProbe;
use App\Application\Shared\DTO\ServiceHealth;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Throwable;

/**
 * Checks the queue backend is reachable and reports its depth.
 *
 * A reachable queue with no worker is "degraded", not "down": requests still
 * succeed, but asynchronous work (metrics sync, exports) will not progress.
 */
final readonly class QueueHealthProbe implements HealthProbe
{
    /** Backlog beyond this suggests workers are not keeping up. */
    private const BACKLOG_WARNING_THRESHOLD = 1_000;

    public function __construct(
        private QueueFactory $queue,
        private ConfigRepository $config,
    ) {}

    public function probe(): ServiceHealth
    {
        $connection = $this->config->get('queue.default');
        $connection = is_string($connection) ? $connection : 'unknown';

        try {
            $size = $this->queue->connection()->size();

            if ($size > self::BACKLOG_WARNING_THRESHOLD) {
                return ServiceHealth::degraded(
                    'queue',
                    sprintf('%s backlog is %d jobs', $connection, $size),
                );
            }

            return ServiceHealth::ok('queue', sprintf('%s (%d queued)', $connection, $size));
        } catch (Throwable $exception) {
            return ServiceHealth::down('queue', $exception->getMessage());
        }
    }
}
