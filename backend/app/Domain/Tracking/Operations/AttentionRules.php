<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Operations;

/**
 * What deserves a look, and the numbers that say so.
 *
 * Every item carries its evidence, and an item is only produced when there is enough of it: a rate over a handful of leads, or a
 * comparison between two links when there are not enough links to compare, is silence and not a warning. These are observations
 * for a person to check — they say what is in the figures, never what to do about it.
 */
final class AttentionRules
{
    public const int MIN_CLICKS = 30;

    public const int MIN_LEADS_FOR_A_RATE = 10;

    public const int MIN_FOR_STEP_RATE = 5;

    public const int MIN_LINKS_TO_COMPARE = 3;

    /**
     * @param  list<array{id: string, name: string, clicks: int, landing_views: ?int, leads: int, qualified: int, appointments: int, sales: int, spend_minor: ?int, spend_basis: string, shared_spend_minor: ?int, cpl_minor: ?int, landing_reported: bool}>  $links
     * @param  array{sla_minutes: ?int, leads: int, attempted_within_sla: ?int, overdue: int, median_attempt_seconds: ?int}  $team
     * @return list<array{code: string, severity: string, subject: ?string, evidence: array<string, int|float|string|null>}>
     */
    public static function evaluate(array $links, array $team): array
    {
        $out = [];

        foreach ($links as $l) {
            if ($l['spend_basis'] !== 'UNAVAILABLE' && ($l['spend_minor'] ?? 0) > 0 && $l['leads'] === 0 && $l['clicks'] >= self::MIN_CLICKS) {
                $out[] = self::item('spend_no_leads', 'attention', $l, ['clicks' => $l['clicks'], 'leads' => 0, 'spend_minor' => $l['spend_minor']]);
            }

            if ($l['spend_basis'] === 'UNAVAILABLE' && ($l['shared_spend_minor'] ?? 0) > 0) {
                $out[] = self::item('spend_not_attributable', 'info', $l, ['shared_spend_minor' => $l['shared_spend_minor']]);
            }

            if ($l['clicks'] >= self::MIN_CLICKS && $l['landing_reported'] === false) {
                $out[] = self::item('tracking_coverage', 'attention', $l, ['clicks' => $l['clicks'], 'landing_views' => $l['landing_views']]);
            }

            if ($l['leads'] >= self::MIN_LEADS_FOR_A_RATE && $l['qualified'] / $l['leads'] < 0.2) {
                $out[] = self::item('low_qualification', 'attention', $l, ['leads' => $l['leads'], 'qualified' => $l['qualified']]);
            }

            if ($l['qualified'] >= self::MIN_FOR_STEP_RATE && $l['appointments'] / $l['qualified'] < 0.2) {
                $out[] = self::item('qualified_few_appointments', 'attention', $l, ['qualified' => $l['qualified'], 'appointments' => $l['appointments']]);
            }

            if ($l['appointments'] >= self::MIN_FOR_STEP_RATE && $l['sales'] / $l['appointments'] < 0.2) {
                $out[] = self::item('appointments_few_sales', 'attention', $l, ['appointments' => $l['appointments'], 'sales' => $l['sales']]);
            }
        }

        $priced = array_values(array_filter($links, static fn (array $l): bool => $l['cpl_minor'] !== null && $l['leads'] >= 3));

        if (count($priced) >= self::MIN_LINKS_TO_COMPARE) {
            $costs = array_map(static fn (array $l): int => (int) $l['cpl_minor'], $priced);
            sort($costs);
            $median = $costs[intdiv(count($costs), 2)];

            foreach ($priced as $l) {
                if ($median > 0 && (int) $l['cpl_minor'] >= (int) round($median * 1.5)) {
                    $out[] = self::item('high_cpl', 'attention', $l, ['cpl_minor' => $l['cpl_minor'], 'median_cpl_minor' => $median]);
                }
            }
        }

        /* Good leads, slow follow-up: enough leads to judge, and most of them were not tried inside the promised time. */
        if ($team['sla_minutes'] !== null && $team['leads'] >= self::MIN_FOR_STEP_RATE && $team['attempted_within_sla'] !== null
            && $team['attempted_within_sla'] / $team['leads'] < 0.5) {
            $out[] = [
                'code' => 'slow_follow_up', 'severity' => 'attention', 'subject' => null,
                'evidence' => ['leads' => $team['leads'], 'attempted_within_sla' => $team['attempted_within_sla'], 'overdue' => $team['overdue'], 'sla_minutes' => $team['sla_minutes'], 'median_attempt_seconds' => $team['median_attempt_seconds']],
            ];
        }

        return $out;
    }

    /**
     * @param  array{id: string, name: string}  $link
     * @param  array<string, int|float|string|null>  $evidence
     * @return array{code: string, severity: string, subject: ?string, evidence: array<string, int|float|string|null>}
     */
    private static function item(string $code, string $severity, array $link, array $evidence): array
    {
        return ['code' => $code, 'severity' => $severity, 'subject' => $link['name'], 'evidence' => ['link_id' => $link['id'], ...$evidence]];
    }
}
