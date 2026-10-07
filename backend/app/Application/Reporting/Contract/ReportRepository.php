<?php

declare(strict_types=1);

namespace App\Application\Reporting\Contract;

use App\Application\Reporting\DTO\ReportDefinition;
use App\Application\Reporting\DTO\ReportSummary;
use App\Application\Reporting\DTO\SavedReport;
use App\Domain\Advertising\MetricSource;
use App\Domain\Shared\ValueObject\DateRange;
use DateTimeImmutable;

interface ReportRepository
{
    public function save(string $workspaceId, ReportDefinition $definition, ?string $actorId): SavedReport;

    public function update(string $workspaceId, string $reportId, ReportDefinition $definition): SavedReport;

    /** @param list<string>|null $visibleProjectIds */
    public function find(string $workspaceId, ?array $visibleProjectIds, string $reportId): ?SavedReport;

    /**
     * Whether the actor may file a report against this client.
     *
     * Asked **before** writing, which is why it lives here rather than being inferred from a failed read: a report
     * saved against a client the author cannot see is a row they can neither open nor delete. The rule is the same
     * one `scope()` applies — a client is visible when the actor has any project with it — and keeping both in this
     * class is what stops them drifting apart.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    public function isClientVisible(string $workspaceId, ?array $visibleProjectIds, string $clientId): bool;

    /**
     * Saved reports, newest first.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<ReportSummary>
     */
    public function list(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId,
        int $limit,
    ): array;

    public function delete(string $workspaceId, string $reportId): void;

    /**
     * Store a rendered report as a snapshot.
     *
     * The payload is **output**, not a query. See `RenderMode`: a snapshot that recomputed would make a figure a
     * client was sent unanswerable.
     *
     * @param  array<string, mixed>  $payload
     */
    public function takeSnapshot(
        string $workspaceId,
        string $reportId,
        DateRange $period,
        array $payload,
        MetricSource $source,
        ?string $label,
        ?string $actorId,
        DateTimeImmutable $at,
    ): string;

    /** @return array{payload: array<string, mixed>, taken_at: DateTimeImmutable, source: MetricSource, label: ?string}|null */
    public function findSnapshot(string $workspaceId, string $snapshotId): ?array;

    /**
     * Snapshots of one report, newest first.
     *
     * @return list<array{id: string, taken_at: DateTimeImmutable, period_from: DateTimeImmutable, period_until: DateTimeImmutable, source: MetricSource, label: ?string, taken_by_name: ?string}>
     */
    public function snapshots(string $workspaceId, string $reportId, int $limit): array;
}
