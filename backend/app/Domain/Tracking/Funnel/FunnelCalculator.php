<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Funnel;

/**
 * Counts, rates and timings for a funnel — pure arithmetic over a cohort, with the honesty rules in one place.
 *
 * - **Every rate divides by the previous stage.** The overall rate is the last stage over the first. There is no other
 *   denominator to choose.
 * - **A rate is null, with a reason, when it cannot be meaningful:** the previous stage is empty, or this stage exceeds it
 *   (which means an earlier stage is not being recorded, not that more people arrived than were there).
 * - **A stage a destination page reports** (a landing view, a form start) is *null* — not zero — when there were clicks
 *   and the page reported nothing: that is a page that is not reporting.
 * - Timings are medians over journeys that reached both stages, in order, so a lead that was merged into an older one
 *   (contacted before this visit) cannot produce a negative wait.
 */
final class FunnelCalculator
{
    /**
     * @param  list<FunnelStage>  $stages
     * @return array{stages: list<array<string, mixed>>, overall_conversion: ?float, overall_reason: ?string, truncated: bool, excluded_sessions: int}
     */
    public static function compute(array $stages, FunnelCohort $cohort): array
    {
        $timestamps = [];
        $counts = [];

        foreach ($stages as $i => $stage) {
            [$counts[$i], $timestamps[$i]] = self::reach($stage->source, $cohort);
        }

        $clicks = count($cohort->sessions);
        $out = [];
        $firstCount = null;
        $lastCount = null;

        foreach ($stages as $i => $stage) {
            $count = $counts[$i];
            $reason = null;
            $state = 'live';

            if ($stage->source->isReportedByPage() && $count === 0 && $clicks > 0) {
                $count = null;
                $state = 'partial';
                $reason = 'The destination page has not reported this step. It may not carry the tracking snippet.';
            }

            $previous = $i === 0 ? null : $counts[$i - 1];
            $conversion = null;
            $conversionReason = null;

            if ($i > 0) {
                if ($count === null || $previous === null) {
                    $conversionReason = $count === null ? 'This step is not being reported.' : 'The step before it is not being reported.';
                } elseif ($previous === 0) {
                    $conversionReason = 'Nobody reached the step before it.';
                } elseif ($count > $previous) {
                    $conversionReason = 'More reached this step than the one before it, so an earlier step is not being recorded.';
                } else {
                    $conversion = round($count / $previous, 4);
                }
            }

            $out[] = [
                ...$stage->toArray(),
                'unit' => $stage->source->unit(),
                'count' => $count,
                'state' => $state,
                'reason' => $reason,
                'conversion_from_previous' => $conversion,
                'conversion_reason' => $conversionReason,
                'drop_off' => $conversion === null || $count === null || $previous === null ? null : $previous - $count,
                'drop_off_rate' => $conversion === null ? null : round(1 - $conversion, 4),
                'median_seconds_from_previous' => $i === 0 ? null : self::median($timestamps[$i - 1], $timestamps[$i]),
            ];

            $firstCount ??= $count;
            $lastCount = $count;
        }

        $overall = null;
        $overallReason = null;

        if ($firstCount === null || $lastCount === null) {
            $overallReason = 'The first or last step is not being reported.';
        } elseif ($firstCount === 0) {
            $overallReason = 'Nobody reached the first step.';
        } elseif ($lastCount > $firstCount) {
            $overallReason = 'The last step exceeds the first, so an earlier step is not being recorded.';
        } else {
            $overall = round($lastCount / $firstCount, 4);
        }

        return ['stages' => $out, 'overall_conversion' => $overall, 'overall_reason' => $overallReason, 'truncated' => $cohort->truncated, 'excluded_sessions' => $cohort->excludedSessions];
    }

    /**
     * How many reached a source, and when each journey (by session index) did.
     *
     * @return array{0: int, 1: array<int, int>}
     */
    private static function reach(FunnelSource $source, FunnelCohort $cohort): array
    {
        $at = [];
        $leads = [];

        foreach ($cohort->sessions as $i => $s) {
            $ts = match ($source) {
                FunnelSource::LinkClick => $s['started'],
                FunnelSource::LandingView => $s['landing'],
                FunnelSource::FormStart => $s['form_start'],
                FunnelSource::FormSubmit => $s['submit'],
                default => self::leadTime($source, $s['lead'], $cohort),
            };

            if ($ts !== null) {
                $at[$i] = $ts;

                if ($s['lead'] !== null && $source->unit() === 'lead') {
                    $leads[$s['lead']] = true;
                }
            }
        }

        $count = match ($source->unit()) {
            'session' => count($at),
            'submission' => $cohort->submissions,
            default => count($leads),
        };

        return [$count, $at];
    }

    private static function leadTime(FunnelSource $source, ?string $leadId, FunnelCohort $cohort): ?int
    {
        $lead = $leadId === null ? null : ($cohort->leads[$leadId] ?? null);

        if ($lead === null) {
            return null;
        }

        return match ($source) {
            FunnelSource::LeadCreated => $lead['received'],
            FunnelSource::LeadContacted => $lead['contacted'],
            FunnelSource::LeadQualified => $lead['qualified'],
            FunnelSource::AppointmentBooked => $lead['appointment'],
            FunnelSource::OpportunityCreated => $lead['opportunity'],
            FunnelSource::SaleCompleted => $lead['won'],
            default => null,
        };
    }

    /**
     * @param  array<int, int>  $from
     * @param  array<int, int>  $to
     */
    private static function median(array $from, array $to): ?int
    {
        $waits = [];

        foreach ($to as $journey => $end) {
            if (isset($from[$journey]) && $end >= $from[$journey]) {
                $waits[] = $end - $from[$journey];
            }
        }

        if ($waits === []) {
            return null;
        }

        sort($waits);
        $n = count($waits);

        return $n % 2 === 1 ? $waits[intdiv($n, 2)] : intdiv($waits[$n / 2 - 1] + $waits[$n / 2], 2);
    }
}
