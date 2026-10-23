<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

use DateTimeImmutable;

/** The people-and-process side of tracking: how the team followed up, and which touches the leads rest on. */
interface TrackingOperationsReadModel
{
    /**
     * Form leads of one project in a window, judged against the response time. Business-process events only.
     *
     * @return array{leads: int, contacted: int, qualified: int, attempted: int, met_sla: ?int, overdue: ?int, median_attempt_seconds: ?int, median_contact_seconds: ?int}
     */
    public function response(string $workspaceId, string $projectId, DateTimeImmutable $from, DateTimeImmutable $to, ?int $slaMinutes): array;

    /**
     * The same, one row per person the leads are with (and one for leads nobody holds).
     *
     * @return list<array{owner_id: ?string, owner_name: ?string, leads: int, contacted: int, qualified: int, attempted: int, met_sla: ?int, overdue: ?int, median_attempt_seconds: ?int, median_contact_seconds: ?int}>
     */
    public function responseByOwner(string $workspaceId, string $projectId, DateTimeImmutable $from, DateTimeImmutable $to, ?int $slaMinutes): array;

    /**
     * The same, keyed by the tracking link each lead's submission came through (`owner_id` carries the link id).
     *
     * @return array<string, array{owner_id: ?string, owner_name: ?string, leads: int, contacted: int, qualified: int, attempted: int, met_sla: ?int, overdue: ?int, median_attempt_seconds: ?int, median_contact_seconds: ?int}>
     */
    public function responseByLink(string $workspaceId, string $projectId, DateTimeImmutable $from, DateTimeImmutable $to, ?int $slaMinutes): array;

    /**
     * Which touch each lead rests on: the one that produced it and the first one its visitor ever made.
     *
     * @return array{leads: int, tracked: int, same_touch: int, different_touch: int, first_touch_unknown: int}
     */
    public function touches(string $workspaceId, string $projectId, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * What the advertising platforms themselves reported for the entities some links are bound to, each counted once.
     *
     * @param  list<array{level: string, id: string}>  $entities
     * @return array{clicks: ?int, results: ?int, entities: int}
     */
    public function platformReported(string $workspaceId, array $entities, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
