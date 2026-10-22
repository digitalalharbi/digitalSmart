<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Client\Contract\ClientVisibility;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Reporting\DTO\ReportDefinition;
use App\Application\Reporting\DTO\ReportSummary;
use App\Application\Reporting\DTO\SavedReport;
use App\Application\Reporting\DTO\SectionDefinition;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Advertising\MetricSource;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Reporting\ComparisonMode;
use App\Domain\Reporting\CreativeView;
use App\Domain\Reporting\ReportAudience;
use App\Domain\Reporting\ReportWindow;
use App\Domain\Reporting\SectionKind;
use App\Domain\Shared\ValueObject\DateRange;
use DateTimeImmutable;
use Exception;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reports, their sections, their snapshots and their shares.
 *
 * Two decisions worth reading.
 *
 * **Sections are replaced wholesale on save, inside a transaction.** A report's sections are an ordered list, not a
 * set of independently editable records: reordering one is a change to all of them, and a partial update that
 * failed halfway would leave a report whose positions no longer form a sequence. The unique constraint on
 * `(report, position)` turns a bad write into a failed one.
 *
 * **A snapshot's payload is stored and never interpreted here.** It is output, written once. The repository does
 * not know its shape, which is deliberate: a repository that parsed it would be a second renderer, and the second
 * renderer is the one that disagrees with the first.
 */
final readonly class EloquentReportRepository implements ReportRepository
{
    public function __construct(
        private IdentifierGenerator $identifiers,
        private ClientVisibility $clients,
    ) {}

    public function save(string $workspaceId, ReportDefinition $definition, ?string $actorId): SavedReport
    {
        $id = $this->identifiers->generate();

        DB::transaction(function () use ($id, $workspaceId, $definition, $actorId): void {
            DB::table('reports')->insert([
                'id' => $id,
                'workspace_id' => $workspaceId,
                'client_id' => $definition->clientId,
                'name' => $definition->name,
                'description' => $definition->description,
                'period_window' => $definition->window->value,
                'custom_from' => $definition->customFrom?->format('Y-m-d'),
                'custom_until' => $definition->customUntil?->format('Y-m-d'),
                'comparison' => $definition->comparison->value,
                'audience' => $definition->audience->value,
                'filters' => json_encode($this->filtersToArray($definition->filters), JSON_THROW_ON_ERROR),
                'internal_note' => $definition->internalNote,
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->writeSections($workspaceId, $id, $definition->sections);
        });

        return $this->find($workspaceId, null, $id) ?? throw new Exception('The report was not written.');
    }

    public function update(string $workspaceId, string $reportId, ReportDefinition $definition): SavedReport
    {
        DB::transaction(function () use ($workspaceId, $reportId, $definition): void {
            DB::table('reports')
                ->where('workspace_id', $workspaceId)
                ->where('id', $reportId)
                ->update([
                    'client_id' => $definition->clientId,
                    'name' => $definition->name,
                    'description' => $definition->description,
                    'period_window' => $definition->window->value,
                    'custom_from' => $definition->customFrom?->format('Y-m-d'),
                    'custom_until' => $definition->customUntil?->format('Y-m-d'),
                    'comparison' => $definition->comparison->value,
                    'audience' => $definition->audience->value,
                    'filters' => json_encode($this->filtersToArray($definition->filters), JSON_THROW_ON_ERROR),
                    'internal_note' => $definition->internalNote,
                    'updated_at' => now(),
                ]);

            /*
             * Replaced, not merged. Sections are an ordered list; reordering one is a change to all of them, and a
             * merge would have to reconcile positions against a unique constraint for no benefit.
             */
            DB::table('report_sections')
                ->where('workspace_id', $workspaceId)
                ->where('report_id', $reportId)
                ->delete();

            $this->writeSections($workspaceId, $reportId, $definition->sections);
        });

        return $this->find($workspaceId, null, $reportId)
            ?? throw new Exception('The report disappeared while being changed.');
    }

    public function find(string $workspaceId, ?array $visibleProjectIds, string $reportId): ?SavedReport
    {
        $row = $this->scope($workspaceId, $visibleProjectIds)->where('r.id', $reportId)->first();

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $values */
        $values = (array) $row;

        return new SavedReport(
            id: (string) ($values['id'] ?? ''),
            definition: $this->definition($values, $this->sections($workspaceId, $reportId)),
            clientName: $this->text($values['client_name'] ?? null),
            createdByName: $this->text($values['created_by_name'] ?? null),
            createdAt: $this->date($values['created_at'] ?? null) ?? new DateTimeImmutable('@0'),
            updatedAt: $this->date($values['updated_at'] ?? null) ?? new DateTimeImmutable('@0'),
        );
    }

    public function isClientVisible(string $workspaceId, ?array $visibleProjectIds, string $clientId): bool
    {
        /*
         * Delegated to the one implementation of the rule, which reporting, the client portal and the assistant
         * all ask. `scope()` below still expresses it as a join, because a list query needs it in SQL rather than
         * as a boolean — but the boolean itself has one home now, and three nearly-identical versions of it would
         * eventually be three rules with the loosest one deciding.
         */
        return $this->clients->isVisible($workspaceId, $visibleProjectIds, $clientId);
    }

    public function list(string $workspaceId, ?array $visibleProjectIds, ?string $clientId, int $limit): array
    {
        $builder = $this->scope($workspaceId, $visibleProjectIds);

        if ($clientId !== null) {
            $builder->where('r.client_id', $clientId);
        }

        $rows = $builder->orderByDesc('r.updated_at')->limit($limit)->get();
        $ids = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $ids[] = (string) ($values['id'] ?? '');
        }

        /*
         * The three counts in one query each, keyed by report, rather than three queries per row. A list of twenty
         * reports otherwise costs sixty queries for figures nobody would miss if they were wrong — which is how a
         * list page becomes the slowest screen in a product.
         */
        $sectionCounts = $this->countBy('report_sections', $workspaceId, $ids);
        $snapshotCounts = $this->countBy('report_snapshots', $workspaceId, $ids);
        $shareStats = $this->shareStats($workspaceId, $ids);

        $summaries = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $id = (string) ($values['id'] ?? '');

            $summaries[] = new ReportSummary(
                id: $id,
                name: (string) ($values['name'] ?? ''),
                description: $this->text($values['description'] ?? null),
                window: ReportWindow::tryFrom((string) ($values['period_window'] ?? ''))
                    ?? ReportWindow::Last30Days,
                audience: ReportAudience::tryFrom((string) ($values['audience'] ?? ''))
                    ?? ReportAudience::Internal,
                clientName: $this->text($values['client_name'] ?? null),
                sectionCount: $sectionCounts[$id] ?? 0,
                snapshotCount: $snapshotCounts[$id] ?? 0,
                activeShareCount: ($shareStats[$id]['live'] ?? 0) + ($shareStats[$id]['frozen'] ?? 0),
                updatedAt: $this->date($values['updated_at'] ?? null) ?? new DateTimeImmutable('@0'),
                createdAt: $this->date($values['created_at'] ?? null),
                createdByName: $this->text($values['created_by_name'] ?? null),
                viewCount: $shareStats[$id]['views'] ?? 0,
                lastViewedAt: $shareStats[$id]['last_viewed'] ?? null,
                nextExpiryAt: $shareStats[$id]['next_expiry'] ?? null,
                liveShareCount: $shareStats[$id]['live'] ?? 0,
                frozenShareCount: $shareStats[$id]['frozen'] ?? 0,
            );
        }

        return $summaries;
    }

    public function delete(string $workspaceId, string $reportId): void
    {
        // Sections, snapshots and shares cascade. A revoked share is not kept after its report is gone.
        DB::table('reports')->where('workspace_id', $workspaceId)->where('id', $reportId)->delete();
    }

    public function takeSnapshot(
        string $workspaceId,
        string $reportId,
        DateRange $period,
        array $payload,
        MetricSource $source,
        ?string $label,
        ?string $actorId,
        DateTimeImmutable $at,
    ): string {
        $id = $this->identifiers->generate();

        DB::table('report_snapshots')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'report_id' => $reportId,
            'period_from' => $period->start->format('Y-m-d'),
            'period_until' => $period->end->format('Y-m-d'),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'source' => $source->value,
            'label' => $label,
            'taken_by' => $actorId,
            'taken_at' => $at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function findSnapshot(string $workspaceId, string $snapshotId): ?array
    {
        $row = DB::table('report_snapshots')
            ->where('workspace_id', $workspaceId)
            ->where('id', $snapshotId)
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $values */
        $values = (array) $row;

        return [
            'payload' => $this->json($values['payload'] ?? null),
            'taken_at' => $this->date($values['taken_at'] ?? null) ?? new DateTimeImmutable('@0'),
            'source' => MetricSource::tryFrom((string) ($values['source'] ?? '')) ?? MetricSource::Synced,
            'label' => $this->text($values['label'] ?? null),
        ];
    }

    public function snapshots(string $workspaceId, string $reportId, int $limit): array
    {
        $rows = DB::table('report_snapshots as s')
            ->leftJoin('users as u', 'u.id', '=', 's.taken_by')
            ->where('s.workspace_id', $workspaceId)
            ->where('s.report_id', $reportId)
            ->orderByDesc('s.taken_at')
            ->limit($limit)
            ->get([
                's.id',
                's.taken_at',
                's.period_from',
                's.period_until',
                's.source',
                's.label',
                'u.name as taken_by_name',
            ]);

        $snapshots = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $snapshots[] = [
                'id' => (string) ($values['id'] ?? ''),
                'taken_at' => $this->date($values['taken_at'] ?? null) ?? new DateTimeImmutable('@0'),
                'period_from' => $this->date($values['period_from'] ?? null) ?? new DateTimeImmutable('@0'),
                'period_until' => $this->date($values['period_until'] ?? null) ?? new DateTimeImmutable('@0'),
                'source' => MetricSource::tryFrom((string) ($values['source'] ?? '')) ?? MetricSource::Synced,
                'label' => $this->text($values['label'] ?? null),
                'taken_by_name' => $this->text($values['taken_by_name'] ?? null),
            ];
        }

        return $snapshots;
    }

    public function sharesAcross(string $workspaceId, ?array $visibleProjectIds, int $limit): array
    {
        $rows = DB::table('report_shares as s')
            ->join('reports as r', 'r.id', '=', 's.report_id')
            ->leftJoin('clients as c', 'c.id', '=', 'r.client_id')
            ->leftJoin('users as u', 'u.id', '=', 's.created_by')
            ->where('s.workspace_id', $workspaceId)
            ->whereIn('s.report_id', $this->scope($workspaceId, $visibleProjectIds)->select('r.id'))
            ->orderByDesc('s.created_at')
            ->limit($limit)
            ->get([
                's.id', 's.report_id', 's.snapshot_id', 's.token_hint', 's.expires_at', 's.revoked_at', 's.revoked_reason',
                's.recipient_label', 's.view_count', 's.last_viewed_at', 's.created_at',
                'r.name as report_name', 'c.name as client_name', 'u.name as created_by_name',
            ]);

        $shares = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $shares[] = [
                'id' => (string) ($values['id'] ?? ''),
                'report_id' => (string) ($values['report_id'] ?? ''),
                'report_name' => (string) ($values['report_name'] ?? ''),
                'client_name' => $this->text($values['client_name'] ?? null),
                'hint' => (string) ($values['token_hint'] ?? ''),
                'snapshot_id' => $this->text($values['snapshot_id'] ?? null),
                'expires_at' => $this->date($values['expires_at'] ?? null) ?? new DateTimeImmutable('@0'),
                'revoked_at' => $this->date($values['revoked_at'] ?? null),
                'revoked_reason' => $this->text($values['revoked_reason'] ?? null),
                'recipient_label' => $this->text($values['recipient_label'] ?? null),
                'view_count' => (int) ($values['view_count'] ?? 0),
                'last_viewed_at' => $this->date($values['last_viewed_at'] ?? null),
                'created_by_name' => $this->text($values['created_by_name'] ?? null),
                'created_at' => $this->date($values['created_at'] ?? null) ?? new DateTimeImmutable('@0'),
            ];
        }

        return $shares;
    }

    public function snapshotsAcross(string $workspaceId, ?array $visibleProjectIds, int $limit): array
    {
        $rows = DB::table('report_snapshots as s')
            ->join('reports as r', 'r.id', '=', 's.report_id')
            ->leftJoin('clients as c', 'c.id', '=', 'r.client_id')
            ->leftJoin('users as u', 'u.id', '=', 's.taken_by')
            ->where('s.workspace_id', $workspaceId)
            ->whereIn('s.report_id', $this->scope($workspaceId, $visibleProjectIds)->select('r.id'))
            ->orderByDesc('s.taken_at')
            ->limit($limit)
            ->get([
                's.id', 's.report_id', 's.taken_at', 's.period_from', 's.period_until', 's.source', 's.label',
                'r.name as report_name', 'c.name as client_name', 'u.name as taken_by_name',
            ]);

        $snapshots = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $snapshots[] = [
                'id' => (string) ($values['id'] ?? ''),
                'report_id' => (string) ($values['report_id'] ?? ''),
                'report_name' => (string) ($values['report_name'] ?? ''),
                'client_name' => $this->text($values['client_name'] ?? null),
                'taken_at' => $this->date($values['taken_at'] ?? null) ?? new DateTimeImmutable('@0'),
                'period_from' => $this->date($values['period_from'] ?? null) ?? new DateTimeImmutable('@0'),
                'period_until' => $this->date($values['period_until'] ?? null) ?? new DateTimeImmutable('@0'),
                'source' => MetricSource::tryFrom((string) ($values['source'] ?? '')) ?? MetricSource::Synced,
                'label' => $this->text($values['label'] ?? null),
                'taken_by_name' => $this->text($values['taken_by_name'] ?? null),
            ];
        }

        return $snapshots;
    }

    /** @param list<SectionDefinition> $sections */
    private function writeSections(string $workspaceId, string $reportId, array $sections): void
    {
        $rows = [];

        foreach ($sections as $position => $section) {
            $rows[] = [
                'id' => $this->identifiers->generate(),
                'workspace_id' => $workspaceId,
                'report_id' => $reportId,
                'position' => $position,
                'kind' => $section->kind->value,
                'title' => $section->title,
                'metrics' => json_encode(
                    array_map(static fn (Metric $metric): string => $metric->value, $section->metrics),
                    JSON_THROW_ON_ERROR,
                ),
                'dimension' => $section->dimension?->value,
                'row_limit' => $section->rowLimit,
                'revenue_source' => $section->revenueSource?->value,
                'creative_view' => $section->creativeView?->value,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('report_sections')->insert($rows);
        }
    }

    /** @return list<SectionDefinition> */
    private function sections(string $workspaceId, string $reportId): array
    {
        $rows = DB::table('report_sections')
            ->where('workspace_id', $workspaceId)
            ->where('report_id', $reportId)
            ->orderBy('position')
            ->get();

        $sections = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            /** @var list<Metric> $metrics */
            $metrics = [];

            foreach ($this->json($values['metrics'] ?? null) as $value) {
                $metric = is_string($value) ? Metric::tryFrom($value) : null;

                if ($metric !== null) {
                    $metrics[] = $metric;
                }
            }

            $sections[] = new SectionDefinition(
                kind: SectionKind::tryFrom((string) ($values['kind'] ?? '')) ?? SectionKind::Scorecard,
                metrics: $metrics,
                title: $this->text($values['title'] ?? null),
                dimension: Dimension::tryFrom((string) ($values['dimension'] ?? '')),
                rowLimit: isset($values['row_limit']) && is_numeric($values['row_limit'])
                    ? (int) $values['row_limit']
                    : null,
                revenueSource: RevenueSource::tryFrom((string) ($values['revenue_source'] ?? '')),
                creativeView: CreativeView::tryFrom((string) ($values['creative_view'] ?? '')),
            );
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<SectionDefinition>  $sections
     */
    private function definition(array $values, array $sections): ReportDefinition
    {
        return new ReportDefinition(
            name: (string) ($values['name'] ?? ''),
            window: ReportWindow::tryFrom((string) ($values['period_window'] ?? '')) ?? ReportWindow::Last30Days,
            comparison: ComparisonMode::tryFrom((string) ($values['comparison'] ?? '')) ?? ComparisonMode::None,
            audience: ReportAudience::tryFrom((string) ($values['audience'] ?? '')) ?? ReportAudience::Internal,
            filters: $this->filtersFromArray($this->json($values['filters'] ?? null)),
            sections: $sections,
            description: $this->text($values['description'] ?? null),
            clientId: $this->text($values['client_id'] ?? null),
            customFrom: $this->date($values['custom_from'] ?? null),
            customUntil: $this->date($values['custom_until'] ?? null),
            internalNote: $this->text($values['internal_note'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    private function filtersToArray(AnalyticsFilters $filters): array
    {
        return [
            'providers' => $filters->providers,
            'external_account_ids' => $filters->externalAccountIds,
            'campaign_ids' => $filters->campaignIds,
            'ad_group_ids' => $filters->adGroupIds,
            'ad_ids' => $filters->adIds,
            'project_ids' => $filters->projectIds,
            'client_id' => $filters->clientId,
        ];
    }

    /** @param array<array-key, mixed> $values */
    private function filtersFromArray(array $values): AnalyticsFilters
    {
        return new AnalyticsFilters(
            providers: $this->strings($values['providers'] ?? null),
            externalAccountIds: $this->strings($values['external_account_ids'] ?? null),
            campaignIds: $this->strings($values['campaign_ids'] ?? null),
            adGroupIds: $this->strings($values['ad_group_ids'] ?? null),
            adIds: $this->strings($values['ad_ids'] ?? null),
            projectIds: $this->strings($values['project_ids'] ?? null),
            clientId: $this->text($values['client_id'] ?? null),
        );
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private function countBy(string $table, string $workspaceId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table($table)
            ->where('workspace_id', $workspaceId)
            ->whereIn('report_id', $ids)
            ->groupBy('report_id')
            ->get(['report_id', DB::raw('count(*) as tally')]);

        $counts = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $counts[(string) ($values['report_id'] ?? '')] = (int) ($values['tally'] ?? 0);
        }

        return $counts;
    }

    /**
     * What each report's links add up to, in one query — views and the last time one was opened over *all* its links
     * (a revoked link was still read while it lived), and the live, frozen and soonest-to-lapse among the active ones.
     *
     * @param  list<string>  $ids
     * @return array<string, array{views: int, last_viewed: ?DateTimeImmutable, next_expiry: ?DateTimeImmutable, live: int, frozen: int}>
     */
    private function shareStats(string $workspaceId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $now = now();
        $rows = DB::table('report_shares')
            ->where('workspace_id', $workspaceId)
            ->whereIn('report_id', $ids)
            ->groupBy('report_id')
            ->get([
                'report_id',
                DB::raw('coalesce(sum(view_count), 0) as views'),
                DB::raw('max(last_viewed_at) as last_viewed'),
                DB::raw('count(*) filter (where revoked_at is null and expires_at > \''.$now->toDateTimeString().'\' and snapshot_id is null) as live'),
                DB::raw('count(*) filter (where revoked_at is null and expires_at > \''.$now->toDateTimeString().'\' and snapshot_id is not null) as frozen'),
                DB::raw('min(expires_at) filter (where revoked_at is null and expires_at > \''.$now->toDateTimeString().'\') as next_expiry'),
            ]);

        $stats = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $stats[(string) ($values['report_id'] ?? '')] = [
                'views' => (int) ($values['views'] ?? 0),
                'last_viewed' => $this->date($values['last_viewed'] ?? null),
                'next_expiry' => $this->date($values['next_expiry'] ?? null),
                'live' => (int) ($values['live'] ?? 0),
                'frozen' => (int) ($values['frozen'] ?? 0),
            ];
        }

        return $stats;
    }

    /**
     * A report reaches a person through its client's projects, or belongs to no client and reaches anyone the
     * permission admits — the same shape the task board and the approval queue use.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    private function scope(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        $builder = DB::table('reports as r')
            ->leftJoin('clients as c', 'c.id', '=', 'r.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.created_by')
            ->where('r.workspace_id', $workspaceId)
            ->select(['r.*', 'c.name as client_name', 'u.name as created_by_name']);

        if ($visibleProjectIds === null) {
            return $builder;
        }

        if ($visibleProjectIds === []) {
            return $builder->whereNull('r.client_id');
        }

        return $builder->where(function (Builder $scope) use ($workspaceId, $visibleProjectIds): void {
            $scope->whereNull('r.client_id')
                ->orWhereIn('r.client_id', function (Builder $sub) use ($workspaceId, $visibleProjectIds): void {
                    $sub->select('client_id')
                        ->from('projects')
                        ->where('workspace_id', $workspaceId)
                        ->whereIn('id', $visibleProjectIds);
                });
        });
    }

    /** @return array<array-key, mixed> */
    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Exception) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (Exception) {
                return null;
            }
        }

        return null;
    }
}
