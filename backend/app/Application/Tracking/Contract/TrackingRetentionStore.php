<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

use DateTimeImmutable;

interface TrackingRetentionStore
{
    /**
     * Roll raw events past each project's retention up into daily counts, then delete them and the sessions they belonged to,
     * and strip the pseudonymous identifiers from older submissions and dispatches.
     *
     * @return array{rolled_up: int, events_deleted: int, sessions_deleted: int, submissions_anonymised: int, dispatches_anonymised: int}
     */
    public function purge(DateTimeImmutable $now): array;
}
