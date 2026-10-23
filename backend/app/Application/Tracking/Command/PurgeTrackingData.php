<?php

declare(strict_types=1);

namespace App\Application\Tracking\Command;

use App\Application\Shared\Contract\Clock;
use App\Application\Tracking\Contract\TrackingRetentionStore;

/**
 * Raw tracking is kept for as long as the project says (400 days unless chosen, never under 30), then rolled up and deleted.
 *
 * What survives is a count per day, per event, per traffic class: enough to remember how a campaign did, not enough to find a
 * person. What is anonymised rather than deleted is the submission itself — it is the record of what somebody asked for, and
 * the lead made from it is the agency's own — but its visitor, session and touchpoint identifiers are removed.
 */
final readonly class PurgeTrackingData
{
    public function __construct(private TrackingRetentionStore $store, private Clock $clock) {}

    /** @return array{rolled_up: int, events_deleted: int, sessions_deleted: int, submissions_anonymised: int, dispatches_anonymised: int} */
    public function __invoke(): array
    {
        return $this->store->purge($this->clock->now());
    }
}
