<?php

declare(strict_types=1);

namespace App\Infrastructure\System;

use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\HealthProbe;
use App\Application\Shared\Contract\WorkLedger;
use App\Application\Shared\DTO\ServiceHealth;
use Throwable;

/**
 * Reports work that gave up.
 *
 * A failed job is the same class of fact as an approved change nobody carried out: **something somebody believes
 * happened, and did not.** A sync that exhausted its retries leaves yesterday's spend on the dashboard; a
 * webhook that failed leaves an order out of the revenue a client is shown. Neither announces itself, and
 * neither is visible in a depth of zero — the queue is empty precisely because the work was abandoned.
 *
 * So any failure at all is **degraded**, with the count and the age of the oldest. Not a threshold: the first
 * failed job is the one worth reading, and a probe that stayed green until ten of them had piled up would hide
 * exactly the quiet single failure this is for.
 */
final readonly class FailedWorkHealthProbe implements HealthProbe
{
    public function __construct(
        private WorkLedger $work,
        private Clock $clock,
    ) {}

    public function probe(): ServiceHealth
    {
        try {
            $failures = $this->work->failures();
        } catch (Throwable $exception) {
            return ServiceHealth::down('failed_jobs', $exception->getMessage());
        }

        if ($failures->isEmpty()) {
            return ServiceHealth::ok('failed_jobs', 'none');
        }

        $waited = $failures->waitedSeconds($this->clock->now());

        if ($waited === null) {
            return ServiceHealth::degraded('failed_jobs', sprintf(
                '%d job(s) gave up; this store does not publish when',
                $failures->count,
            ));
        }

        return ServiceHealth::degraded('failed_jobs', sprintf(
            '%d job(s) gave up, the oldest %s ago — each one is work that did not happen',
            $failures->count,
            $this->elapsed($waited),
        ));
    }

    /** Hours and days, because the figure a reader acts on is "how long has this been true". */
    private function elapsed(int $seconds): string
    {
        if ($seconds < 3600) {
            return sprintf('%d minute(s)', intdiv($seconds, 60));
        }

        return $seconds < 86_400
            ? sprintf('%d hour(s)', intdiv($seconds, 3600))
            : sprintf('%d day(s)', intdiv($seconds, 86_400));
    }
}
