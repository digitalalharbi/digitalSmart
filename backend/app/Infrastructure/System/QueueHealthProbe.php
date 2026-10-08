<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\HealthProbe;
use App\Application\Shared\Contract\WorkLedger;
use App\Application\Shared\DTO\ServiceHealth;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Checks the queue backend is reachable, and whether anything is consuming it.
 *
 * Reachability is the easy half. The half that matters is **whether work is moving**, because a reachable queue
 * with no worker is the failure this probe exists to catch: requests still succeed, and the asynchronous work
 * behind them — a provider sync, a webhook, an export — silently never happens. Every figure that work would
 * have refreshed stays on the screen looking current.
 *
 * Depth cannot answer that. Five jobs on a dead queue and five thousand on one keeping up read the same from a
 * count, and the first is the emergency. So the signal is the **age of the oldest waiting job**: past the
 * threshold nothing is consuming, whatever the depth. Where the driver does not publish an age the detail says
 * so, because reporting "nothing stalled" after failing to look would be worse than reporting less.
 *
 * Degraded, never down. A stalled worker is not an outage of the platform and taking the whole API offline for
 * it — which a 503 from this endpoint would do behind a load balancer — would turn a delayed sync into downtime.
 */
final readonly class QueueHealthProbe implements HealthProbe
{
    /** Backlog beyond this suggests workers are not keeping up, even while they are consuming. */
    private const int BACKLOG_WARNING_THRESHOLD = 1_000;

    /**
     * How long the oldest job may wait before nothing is plausibly consuming the queue.
     *
     * Five minutes rather than one: the longest `backoff` this product schedules is fifteen minutes, so a job
     * deliberately deferred by a provider's rate limit would otherwise read as a stalled worker — and a probe
     * that cries wolf at a working retry is a probe somebody turns off. This catches an absent consumer, which
     * takes minutes to matter and hours to notice by hand.
     */
    private const int STALLED_AFTER_SECONDS = 300;

    public function __construct(
        private WorkLedger $work,
        private ConfigRepository $config,
        private Clock $clock,
    ) {}

    public function probe(): ServiceHealth
    {
        $connection = $this->config->get('queue.default');
        $connection = is_string($connection) ? $connection : 'unknown';

        try {
            $backlog = $this->work->backlog();
        } catch (Throwable $exception) {
            return ServiceHealth::down('queue', $exception->getMessage());
        }

        if ($backlog->isEmpty()) {
            return ServiceHealth::ok('queue', sprintf('%s (nothing queued)', $connection));
        }

        $waited = $backlog->waitedSeconds($this->clock->now());

        if ($waited !== null && $waited >= self::STALLED_AFTER_SECONDS) {
            return ServiceHealth::degraded('queue', sprintf(
                '%s has %d job(s) waiting and the oldest has waited %d minutes — nothing appears to be consuming it',
                $connection,
                $backlog->count,
                intdiv($waited, 60),
            ));
        }

        if ($backlog->count > self::BACKLOG_WARNING_THRESHOLD) {
            return ServiceHealth::degraded(
                'queue',
                sprintf('%s backlog is %d jobs', $connection, $backlog->count),
            );
        }

        /*
         * Moving, as far as can be seen. The second clause is not padding: a reader who is told "12 queued" and
         * nothing else cannot know whether this probe would have noticed a stalled worker on this driver.
         */
        return $backlog->ageIsKnown
            ? ServiceHealth::ok('queue', sprintf(
                '%s (%d queued, oldest %d seconds)',
                $connection,
                $backlog->count,
                $waited ?? 0,
            ))
            : ServiceHealth::ok('queue', sprintf(
                '%s (%d queued; this driver does not publish job ages, so a stalled worker is not detected here)',
                $connection,
                $backlog->count,
            ));
    }
}
