<?php

declare(strict_types=1);

namespace App\Application\Tracking\DTO;

use App\Domain\Tracking\ConsentState;
use App\Domain\Tracking\TrackingEventName;
use App\Domain\Tracking\TrafficClass;
use DateTimeImmutable;

/** Everything the dispatcher needs to decide whether, and what, to send for one conversion. */
final readonly class DispatchContext
{
    /** @param array<string, string> $clickIds */
    public function __construct(
        public string $workspaceId,
        public string $projectId,
        public TrackingEventName $event,
        public string $eventId,
        public ConsentState $consent,
        public TrafficClass $trafficClass,
        public array $clickIds,
        public DateTimeImmutable $occurredAt,
        public ?string $leadId = null,
    ) {}
}
