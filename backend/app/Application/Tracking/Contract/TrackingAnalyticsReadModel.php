<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

use App\Domain\Tracking\Funnel\FunnelCohort;
use DateTimeImmutable;

interface TrackingAnalyticsReadModel
{
    /** The visits in a window and what became of each. Already scoped: the caller has checked the project is visible. */
    public function cohort(string $workspaceId, string $projectId, ?string $linkId, DateTimeImmutable $from, DateTimeImmutable $to): FunnelCohort;

    /**
     * Visits grouped by one dimension, each with how far its visitors got.
     *
     * @return list<array<string, mixed>>
     */
    public function breakdown(string $workspaceId, string $projectId, ?string $linkId, string $dimension, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * The tracked path of one lead: what this product recorded before and after it existed.
     *
     * @return list<array<string, mixed>>
     */
    public function leadTimeline(string $workspaceId, string $leadId): array;

    /**
     * How the team followed up leads that came from forms.
     *
     * @return array<string, mixed>
     */
    public function followUp(string $workspaceId, string $projectId, DateTimeImmutable $from, DateTimeImmutable $to, int $slaMinutes): array;
}
