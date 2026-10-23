<?php

declare(strict_types=1);

namespace App\Application\Tracking\DTO;

use App\Domain\Tracking\ConsentState;
use App\Domain\Tracking\TrafficClass;

/** The session a browser event belongs to, resolved from the opaque touchpoint id the destination was given. */
final readonly class Touchpoint
{
    public function __construct(
        public string $touchpointId,
        public string $workspaceId,
        public string $projectId,
        public ?string $trackingLinkId,
        public string $visitorId,
        public string $sessionId,
        public TrafficClass $trafficClass,
        public ConsentState $consent,
    ) {}
}
