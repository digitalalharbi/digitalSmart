import type { MetricValue } from '@/shared/metrics';
import type { Ad, Creative } from './hierarchyData';

/**
 * Which of a campaign's creatives are working, from the figures the platform reported for them and nothing else.
 *
 * A creative is judged only once it has been *seen* enough for a rate to mean something, by the same line the Creative
 * agent uses; below it the creative is "not enough data" — not weak, not strong, and in none of the lists. The labels
 * are plain rules over two or three figures, each of which travels with the card, so a person can disagree with one.
 * Nothing here estimates fatigue from a model: it reads the click-through change the hierarchy already carries.
 */

/** Impressions a creative needs before a rate is read. The Creative agent's own floor. */
export const MIN_IMPRESSIONS = 1000;
/** A click-through change at or below this is tiring. The Creative agent and the hierarchy tree use the same line. */
export const TIRING = -0.25;
/** A click-through change at or above this is rising: the same distance, the other way. */
export const RISING = 0.25;

export type CreativeState = 'rising' | 'steady' | 'tiring' | 'needs_replacing' | 'not_enough_data';

export type CreativeItem = {
  id: string;
  name: string;
  groupName: string;
  creative: Creative | null;
  format: string | null;
  state: CreativeState;
  /** The platform's own figures for this ad, as the analytics layer publishes them. */
  figures: Record<string, MetricValue>;
  /** Relative change in click-through rate against the week before, as the hierarchy reports it. */
  ctrChange: number | null;
  frequency: number | null;
  impressions: number | null;
};

export type AdFigures = Record<string, Record<string, MetricValue>>;

function median(values: number[]): number | null {
  if (values.length === 0) {
    return null;
  }

  const sorted = [...values].sort((a, b) => a - b);
  const mid = Math.floor(sorted.length / 2);

  return sorted.length % 2 === 1
    ? (sorted[mid] as number)
    : ((sorted[mid - 1] as number) + (sorted[mid] as number)) / 2;
}

/**
 * Every ad of a campaign with the state its numbers support.
 *
 * `needs_replacing` is a tiring creative that is also below the campaign's own median click-through rate: it has lost
 * its audience *and* is behind its siblings. A tiring creative still ahead of them is only tiring.
 */
export function creativeItems(
  groups: Array<{ name: string; ads: Ad[] }>,
  figures: AdFigures,
): CreativeItem[] {
  const base = groups.flatMap((group) =>
    group.ads.map((ad) => {
      const values = figures[ad.id] ?? {};
      const impressions = values['impressions']?.value ?? null;

      return {
        id: ad.id,
        name: ad.name,
        groupName: group.name,
        creative: ad.creative,
        format: ad.creative?.format ?? null,
        figures: values,
        ctrChange: ad.ctr_change,
        frequency: ad.frequency,
        impressions,
        ctr: ad.ctr_now,
      };
    }),
  );

  const seen = base.filter((ad) => (ad.impressions ?? 0) >= MIN_IMPRESSIONS);
  const campaignCtr = median(seen.flatMap((ad) => (ad.ctr === null ? [] : [ad.ctr])));

  return base.map(({ ctr, ...ad }) => {
    const enough = (ad.impressions ?? 0) >= MIN_IMPRESSIONS;
    const change = ad.ctrChange;

    let state: CreativeState = 'steady';

    if (!enough) {
      state = 'not_enough_data';
    } else if (change !== null && change <= TIRING) {
      state =
        campaignCtr !== null && ctr !== null && ctr < campaignCtr ? 'needs_replacing' : 'tiring';
    } else if (change !== null && change >= RISING) {
      state = 'rising';
    }

    return { ...ad, state };
  });
}

/** The ads worth a person's look, by the question they are asking. Each list is bounded; none is the whole campaign. */
export function creativeViews(
  items: CreativeItem[],
): Record<'top' | 'rising' | 'tiring' | 'replace', CreativeItem[]> {
  const judged = items.filter((item) => item.state !== 'not_enough_data');
  const results = (item: CreativeItem): number => item.figures['conversions']?.value ?? 0;
  const ctr = (item: CreativeItem): number => item.figures['ctr']?.value ?? 0;

  return {
    // Results first — what the campaign is for — then click-through rate, then spend, so the order never depends on luck.
    top: [...judged]
      .sort(
        (a, b) =>
          results(b) - results(a) ||
          ctr(b) - ctr(a) ||
          (b.figures['spend']?.value ?? 0) - (a.figures['spend']?.value ?? 0),
      )
      .slice(0, 6),
    rising: judged.filter((item) => item.state === 'rising').slice(0, 6),
    tiring: judged
      .filter((item) => item.state === 'tiring' || item.state === 'needs_replacing')
      .slice(0, 6),
    replace: judged.filter((item) => item.state === 'needs_replacing').slice(0, 6),
  };
}

/**
 * A campaign's spend and results by creative format, for the formats it actually ran.
 *
 * Shares only of what adds up (spend, results), never a ratio: a format's click-through rate is not the mean of its
 * ads' rates, and this module does not compute one. A format with a single creative is still a format; an ad with no
 * creative format is left out rather than called something.
 */
export function formatMix(
  items: CreativeItem[],
): Array<{ format: string; spend: number; results: number }> {
  const byFormat = new Map<string, { spend: number; results: number }>();

  for (const item of items) {
    if (item.format === null) {
      continue;
    }

    const entry = byFormat.get(item.format) ?? { spend: 0, results: 0 };
    entry.spend += item.figures['spend']?.value ?? 0;
    entry.results += item.figures['conversions']?.value ?? 0;
    byFormat.set(item.format, entry);
  }

  return [...byFormat.entries()]
    .map(([format, totals]) => ({ format, ...totals }))
    .sort((a, b) => b.spend - a.spend);
}
