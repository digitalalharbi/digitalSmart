<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/**
 * Calling a creative top, rising, stable, fatiguing or weak — or declining to — from its own figures.
 *
 * **No model, no guess.** Every verdict is a plain rule over figures the platforms reported, and every rule has a floor:
 * a creative seen fewer than {@see self::MIN_IMPRESSIONS} times gets no verdict at all, because a rate over a few dozen
 * impressions is noise, and "not enough evidence" is a different statement from "weak".
 *
 * **Fatigue is a pattern, not one number.** A click-through rate that fell by a quarter is only the first signal; a
 * creative is called fatiguing when it fell *and* something else got worse in the same comparison — the same people seeing
 * it more often, a result costing more, a smaller share of clicks converting. A fall that nothing corroborates is left
 * unclassified, with the fall in the evidence, rather than called fatigue on the strength of one figure. Both periods must
 * clear the impression floor, or there is no comparison to make.
 *
 * **Top and weak are relative to the creative's own cohort** — the creatives in the same filtered set — and need that cohort
 * to exist: one creative alone is not the best of anything.
 */
final class CreativeAssessor
{
    public const int MIN_IMPRESSIONS = 1000;

    /** Relative click-through change at or beyond which a creative is rising or tiring. The Creative agent's own line. */
    public const float CTR_MOVE = 0.25;

    /** A rise in frequency worth corroborating with. */
    public const float FREQUENCY_RISE = 0.10;

    /** A rise in cost per result worth corroborating with. */
    public const float COST_RISE = 0.20;

    /** A fall in the share of clicks that convert worth corroborating with. */
    public const float CONVERSION_FALL = 0.20;

    /** A cohort smaller than this has no "top" and no "weak": ranking three things is not selection. */
    public const int MIN_COHORT = 4;

    /**
     * @param  array<string, CreativeFigures>  $current  keyed by creative
     * @param  array<string, CreativeFigures>  $previous  keyed by creative; a creative absent here had no earlier period
     * @return array<string, CreativeAssessment>
     */
    public function assess(array $current, array $previous): array
    {
        $eligible = array_filter(
            $current,
            static fn (CreativeFigures $figures): bool => ($figures->impressions ?? 0) >= self::MIN_IMPRESSIONS,
        );

        $medianSpend = self::median(array_values(array_filter(array_map(static fn (CreativeFigures $f): ?float => $f->spend, $eligible), static fn (?float $v): bool => $v !== null)));
        $medianCtr = self::median(array_values(array_filter(array_map(static fn (CreativeFigures $f): ?float => $f->ctr, $eligible), static fn (?float $v): bool => $v !== null)));
        $cohortHasResults = array_filter($eligible, static fn (CreativeFigures $f): bool => ($f->results ?? 0) > 0) !== [];

        $assessments = [];
        $undecided = [];

        foreach ($current as $key => $figures) {
            if (! isset($eligible[$key])) {
                $assessments[$key] = new CreativeAssessment(null);

                continue;
            }

            [$changes, $verdict] = $this->movement($figures, $previous[$key] ?? null);
            $evidence = $changes;

            if ($verdict === CreativeVerdict::Fatiguing || $verdict === CreativeVerdict::Rising) {
                $assessments[$key] = new CreativeAssessment($verdict, $evidence);

                continue;
            }

            // A fall nothing corroborates is not classified at all: it is named, and left for a person to look at.
            if ($this->fellWithoutCorroboration($changes)) {
                $assessments[$key] = new CreativeAssessment(null, $evidence);

                continue;
            }

            $undecided[$key] = $evidence;
        }

        $cohort = array_intersect_key($current, $undecided);
        $topKeys = [];

        if (count($eligible) >= self::MIN_COHORT) {
            $ranked = $cohort;
            uasort($ranked, static fn (CreativeFigures $a, CreativeFigures $b): int => ($b->results ?? 0) <=> ($a->results ?? 0)
                ?: ($b->ctr ?? 0) <=> ($a->ctr ?? 0)
                ?: ($b->spend ?? 0) <=> ($a->spend ?? 0));

            $room = min(3, max(1, (int) ceil(count($eligible) / 4)));

            foreach ($ranked as $key => $figures) {
                if (count($topKeys) >= $room) {
                    break;
                }

                // With any results in the cohort, a top creative has some; without, it is judged on click-through alone.
                $earns = $cohortHasResults
                    ? ($figures->results ?? 0) > 0
                    : $medianCtr !== null && ($figures->ctr ?? 0) > $medianCtr;

                if ($earns) {
                    $topKeys[] = $key;
                }
            }
        }

        foreach ($undecided as $key => $evidence) {
            $figures = $current[$key];

            if (in_array($key, $topKeys, true)) {
                $assessments[$key] = new CreativeAssessment(CreativeVerdict::Top, $evidence);

                continue;
            }

            $assessments[$key] = new CreativeAssessment(
                count($eligible) >= self::MIN_COHORT && $this->isWeak($figures, $medianSpend, $medianCtr)
                    ? CreativeVerdict::Weak
                    : CreativeVerdict::Stable,
                $evidence,
            );
        }

        return $assessments;
    }

    /**
     * What changed against the period before, and the verdict that change alone supports.
     *
     * @return array{0: list<array{signal: string, value: float}>, 1: ?CreativeVerdict}
     */
    private function movement(CreativeFigures $now, ?CreativeFigures $before): array
    {
        if ($before === null || ($before->impressions ?? 0) < self::MIN_IMPRESSIONS) {
            return [[], null];
        }

        $changes = [];
        $ctr = self::change($now->ctr, $before->ctr);
        $frequency = self::change($now->frequency, $before->frequency);
        $cost = self::change($now->costPerResult, $before->costPerResult);
        $conversion = self::change($now->conversionRate, $before->conversionRate);

        foreach (['ctr_change' => $ctr, 'frequency_change' => $frequency, 'cost_change' => $cost, 'conversion_rate_change' => $conversion] as $signal => $value) {
            if ($value !== null) {
                $changes[] = ['signal' => $signal, 'value' => round($value, 4)];
            }
        }

        $corroborated = ($frequency !== null && $frequency >= self::FREQUENCY_RISE)
            || ($cost !== null && $cost >= self::COST_RISE)
            || ($conversion !== null && $conversion <= -self::CONVERSION_FALL);

        if ($ctr !== null && $ctr <= -self::CTR_MOVE && $corroborated) {
            return [$changes, CreativeVerdict::Fatiguing];
        }

        if ($ctr !== null && $ctr >= self::CTR_MOVE && ! ($cost !== null && $cost >= self::COST_RISE)) {
            return [$changes, CreativeVerdict::Rising];
        }

        return [$changes, null];
    }

    /** @param list<array{signal: string, value: float}> $changes */
    private function fellWithoutCorroboration(array $changes): bool
    {
        foreach ($changes as $change) {
            if ($change['signal'] === 'ctr_change' && $change['value'] <= -self::CTR_MOVE) {
                return true;
            }
        }

        return false;
    }

    private function isWeak(CreativeFigures $figures, ?float $medianSpend, ?float $medianCtr): bool
    {
        // It has had its share of the money; a creative that was barely run has not been given the chance to be weak.
        if ($medianSpend === null || ($figures->spend ?? 0) < $medianSpend) {
            return false;
        }

        $noResults = $figures->results !== null && $figures->results <= 0;
        $farBehind = $medianCtr !== null && $figures->ctr !== null && $figures->ctr < $medianCtr * 0.5;

        return $noResults || $farBehind;
    }

    /** The relative change, or null where either side is missing or the earlier one is zero — no percentage from nothing. */
    private static function change(?float $now, ?float $before): ?float
    {
        if ($now === null || $before === null || $before <= 0) {
            return null;
        }

        return ($now - $before) / $before;
    }

    /** @param list<float> $values */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
