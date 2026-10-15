<?php

declare(strict_types=1);

namespace App\Application\Measurement\Query;

use App\Application\Measurement\DTO\MeasurementSourceView;
use App\Domain\Measurement\TrackingSeverity;

/**
 * "92 projects healthy, 5 warning, 3 critical" - and which ones.
 *
 * Rolled up from `GetTrackingHealth`, never recomputed: severity for a project is the worst severity among
 * the issues whose subject is one of its sources. A project with no source at all never appears here, and
 * that is deliberate rather than a gap: it is not healthy, and the workspace-level `no_source` finding is
 * what says so — counted in `workspace_issues` rather than being folded into a green total.
 *
 * Grouped by project because that is the unit somebody opens next, and by measurement kind (GA4, GTM,
 * store, CRM, ad platform) because "which of my stores are silent" is a different question from "which of
 * my GA4 properties are".
 */
final readonly class GetAgencyTrackingHealth
{
    public function __construct(private GetTrackingHealth $health) {}

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        $view = ($this->health)();

        /** @var array<string, list<MeasurementSourceView>> $byProject */
        $byProject = [];
        $names = [];

        foreach ($view->sources as $source) {
            $key = $source->projectId ?? 'client:'.$source->clientId;
            $byProject[$key][] = $source;
            $names[$key] = [
                'project_id' => $source->projectId,
                'project_name' => $source->projectName ?? $source->clientName,
                'client_id' => $source->clientId,
                'client_name' => $source->clientName,
            ];
        }

        /** @var array<string, string> $severityBySubject */
        $severityBySubject = [];

        foreach ($view->issues as $issue) {
            if ($issue->subject !== null) {
                $current = $severityBySubject[$issue->subject] ?? null;
                $severityBySubject[$issue->subject] = $this->worse($current, $issue->severity->value);
            }
        }

        $projects = [];
        $totals = ['healthy' => 0, 'warning' => 0, 'critical' => 0];
        $byKind = [];

        foreach ($byProject as $key => $sources) {
            $worst = null;
            $attention = 0;

            foreach ($sources as $source) {
                $worst = $this->worse($worst, $severityBySubject[$source->id] ?? null);
                $needs = $source->freshness->needsAttention();
                $attention += $needs ? 1 : 0;

                if ($needs && $worst === null) {
                    $worst = TrackingSeverity::Warning->value;
                }

                $kind = $source->kind->value;
                $byKind[$kind] ??= ['kind' => $kind, 'sources' => 0, 'needing_attention' => 0];
                $byKind[$kind]['sources']++;
                $byKind[$kind]['needing_attention'] += $needs ? 1 : 0;
            }

            $status = match ($worst) {
                TrackingSeverity::Critical->value => 'critical',
                TrackingSeverity::Warning->value => 'warning',
                default => 'healthy',
            };

            $totals[$status]++;
            $projects[] = $names[$key] + [
                'status' => $status,
                'sources' => count($sources),
                'sources_needing_attention' => $attention,
            ];
        }

        usort($projects, static fn (array $a, array $b): int => [self::rank($a['status']), $a['project_name']]
            <=> [self::rank($b['status']), $b['project_name']]);

        ksort($byKind);

        return [
            'totals' => $totals,
            'projects' => $projects,
            'by_kind' => array_values($byKind),
            'workspace_issues' => count(array_filter($view->issues, static fn ($i): bool => $i->subject === null)),
        ];
    }

    private function worse(?string $a, ?string $b): ?string
    {
        $order = [TrackingSeverity::Info->value => 1, TrackingSeverity::Warning->value => 2, TrackingSeverity::Critical->value => 3];

        return ($order[$a ?? ''] ?? 0) >= ($order[$b ?? ''] ?? 0) ? $a : $b;
    }

    private static function rank(string $status): int
    {
        return match ($status) {
            'critical' => 0,
            'warning' => 1,
            default => 3,
        };
    }
}
