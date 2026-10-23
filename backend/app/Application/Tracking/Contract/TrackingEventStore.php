<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

use App\Application\Tracking\DTO\LinkCounts;
use App\Application\Tracking\DTO\LinkTarget;
use App\Application\Tracking\DTO\Touchpoint;
use App\Domain\Tracking\ConsentState;
use App\Domain\Tracking\TrackingEventName;
use App\Domain\Tracking\TrafficClass;
use DateTimeImmutable;

interface TrackingEventStore
{
    /**
     * Begin a session for a click and record the click and the session start.
     *
     * @param  array<string, string>  $clickIds
     */
    public function recordClick(
        LinkTarget $link,
        string $visitorId,
        string $sessionId,
        string $touchpointId,
        string $clickEventId,
        string $startEventId,
        TrafficClass $class,
        ConsentState $consent,
        ?string $referrerHost,
        string $deviceClass,
        string $browserClass,
        string $landingPath,
        array $clickIds,
        DateTimeImmutable $at,
    ): void;

    public function touchpoint(string $touchpointId): ?Touchpoint;

    /**
     * Record a browser-reported event once. Returns false for a repeat of an event id already kept.
     *
     * @param  array<string, scalar>  $metadata  already reduced to the keys that may be kept
     */
    public function recordBrowserEvent(
        Touchpoint $touchpoint,
        TrackingEventName $name,
        string $eventId,
        ConsentState $consent,
        ?string $landingPath,
        array $metadata,
        DateTimeImmutable $at,
    ): bool;

    /**
     * @param  list<string>  $linkIds
     * @return array<string, LinkCounts> by link id
     */
    public function countsFor(array $linkIds, bool $includeExcluded = false): array;
}
