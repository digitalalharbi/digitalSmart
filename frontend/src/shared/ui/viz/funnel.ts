/**
 * What each step of a funnel says, worked out from the counts it was given and nothing else.
 *
 * The rate of a step is its count over the count before it, and it is **absent** — not zero, not capped — when either
 * is missing, when the earlier one is zero, or when the step is larger than the one before (a purchase after a view, not
 * a click: this product holds aggregate counts, not people, so a later stage can exceed an earlier one, and a rate over a
 * hundred would claim a conversion that is a different population). The drop-off is what the rate leaves out.
 *
 * **The leak** is the step, among those from stage index `leakFrom` on, with the lowest rate — and only when at least two steps are
 * competing. With one candidate it would be the largest by default, and a highlight that cannot fail to appear is not
 * information. An advertising funnel starts the search at its third stage (index 2), after the first step (impressions to clicks is click-through
 * rate, which is always the steepest drop and is judged on its own); a lead funnel searches from its first step (index 1).
 */
export type FunnelStep = {
  /** 0–100, or null where there is nothing honest to divide. */
  rate: number | null;
  dropOff: number | null;
  isLeak: boolean;
};

export function funnelSteps(
  values: ReadonlyArray<number | null>,
  leakFrom: number | null = null,
): FunnelStep[] {
  const rates = values.map((value, index): number | null => {
    const previous = index === 0 ? null : (values[index - 1] ?? null);

    return value !== null && previous !== null && previous > 0 && value <= previous
      ? Math.round((value / previous) * 100)
      : null;
  });

  let leak = -1;

  if (leakFrom !== null) {
    const candidates = rates
      .map((rate, index) => ({ rate, index }))
      .filter(
        (entry): entry is { rate: number; index: number } =>
          entry.index >= leakFrom && entry.rate !== null,
      );

    if (candidates.length >= 2) {
      // Lowest rate wins; on a tie the earlier step, which is the one nearer the top of the funnel.
      leak = candidates.reduce((worst, entry) => (entry.rate < worst.rate ? entry : worst)).index;
    }
  }

  return rates.map((rate, index) => ({
    rate,
    dropOff: rate === null ? null : 100 - rate,
    isLeak: index === leak,
  }));
}
