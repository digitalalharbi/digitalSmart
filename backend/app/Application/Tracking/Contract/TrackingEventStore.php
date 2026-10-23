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
     * What the click that began a touchpoint's session carried: campaign parameters, click identifiers, the referrer.
     *
     * @return array{utm: array<string, string>, click_ids: array<string, string>, referrer_host: ?string, landing_path: ?string}
     */
    public function attributionOf(Touchpoint $touchpoint): array;

    /** Record an event this product wrote itself (a submission, a lead). False for a repeat of the same event id. */
    public function recordServerEvent(
        string $workspaceId,
        string $projectId,
        ?Touchpoint $touchpoint,
        ?string $trackingLinkId,
        TrackingEventName $name,
        string $eventId,
        string $visitorId,
        ConsentState $consent,
        TrafficClass $class,
        DateTimeImmutable $at,
    ): bool;

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
