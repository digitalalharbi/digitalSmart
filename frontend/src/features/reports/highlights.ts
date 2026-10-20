import type { MetricValue } from '@/shared/metrics';
import type { RenderedReport, RenderedSection } from './types';

/*
 * What a report already says, picked out for a reader in a hurry.
 *
 * Nothing here computes a figure. Every number shown comes from the report: a scorecard value, a change the server
 * worked out (and withheld when the periods were not comparable), a breakdown row. This module only decides which
 * of them are worth a card, by rules a reader can state in a sentence — and each card carries the figures that made
 * it, so a client can disagree with it.
 */

/** A change smaller than this is noise in a month of advertising, and a card for it teaches a reader to skim. */
const NOTICEABLE = 0.05;
/** A change in the wrong direction at least this large is something to look at, not merely to note. */
const ATTENTION = 0.15;
/** A campaign this much worse than the report's own figure is lagging; less than that is the ordinary spread. */
const LAGGING = 0.25;
/** A platform this much better than the report's own figure has earned a second look. */
const LEADING = 1.25;
const MAX_INSIGHTS = 4;
const MAX_CARDS = 3;

export type InsightKind = 'improved' | 'declined' | 'attention' | 'best';

export type Insight = {
  key: string;
  kind: InsightKind;
  value: MetricValue;
  /** Whose figure it is, for a best-performer card; the whole report otherwise. */
  subject: string | null;
};

export type CampaignCard = {
  key: string;
  label: string;
  /** Spend, results, revenue and the like, as the report carries them. */
  values: Record<string, MetricValue>;
  /** The efficiency figure the ranking was judged on, and the report's own figure beside it. */
  efficiency: MetricValue | null;
  against: MetricValue | null;
};

export type Recommendation = {
  key: 'allocate' | 'creative';
  subject: string | null;
  /** The evidence: what was seen, and what it was seen against. */
  current: MetricValue;
  against: MetricValue | null;
};

function sections(report: RenderedReport, kind: RenderedSection['kind']): RenderedSection[] {
  return report.sections.filter((section) => section.kind === kind);
}

/** Every scorecard figure in the report, first occurrence wins. */
export function scorecardValues(report: RenderedReport): Record<string, MetricValue> {
  const out: Record<string, MetricValue> = {};

  for (const section of sections(report, 'scorecard')) {
    for (const [metric, value] of Object.entries(section.totals?.values ?? {})) {
      out[metric] ??= value;
    }
  }

  return out;
}

function breakdown(report: RenderedReport, dimension: string): RenderedSection | null {
  return (
    sections(report, 'breakdown').find(
      (section) => section.dimension === dimension && section.rows.length > 0,
    ) ?? null
  );
}

/** Which figure a ranking is judged on: a return where there is one, else a cost. */
function efficiencyMetric(section: RenderedSection): string | null {
  return ['roas', 'cpa', 'cpl'].find((metric) => section.metrics.includes(metric)) ?? null;
}

/** How much worse than `against` this figure is, as a share of it; negative when it is better. */
function shortfall(value: MetricValue, against: MetricValue): number | null {
  if (value.value === null || against.value === null || against.value === 0) {
    return null;
  }

  const ratio = (against.value - value.value) / Math.abs(against.value);

  return value.lower_is_better ? -ratio : ratio;
}

export function campaignCards(report: RenderedReport): {
  top: CampaignCard[];
  attention: CampaignCard[];
} {
  const section = breakdown(report, 'campaign');

  if (section === null) {
    return { top: [], attention: [] };
  }

  const metric = efficiencyMetric(section);
  const account = metric === null ? null : (scorecardValues(report)[metric] ?? null);

  const cards: CampaignCard[] = section.rows.map((row) => ({
    key: row.key,
    label: row.label,
    values: row.metrics.values,
    efficiency: metric === null ? null : (row.metrics.values[metric] ?? null),
    against: account,
  }));

  /* The server ordered the rows by the first metric, so the first rows are the largest. */
  const top = cards.slice(0, MAX_CARDS);
  const shown = new Set(top.map((card) => card.key));

  const attention = cards
    .filter((card) => !shown.has(card.key))
    .map((card) => ({
      card,
      gap:
        card.efficiency === null || account === null ? null : shortfall(card.efficiency, account),
    }))
    .filter(
      (entry): entry is { card: CampaignCard; gap: number } =>
        entry.gap !== null && entry.gap >= LAGGING,
    )
    .sort((a, b) => b.gap - a.gap)
    .slice(0, MAX_CARDS)
    .map((entry) => entry.card);

  return { top, attention };
}

export function insights(report: RenderedReport): Insight[] {
  const cards: Array<Insight & { weight: number }> = [];

  for (const [metric, value] of Object.entries(scorecardValues(report))) {
    const ratio = value.change_ratio;

    if (ratio === null || value.change_is_good === null || Math.abs(ratio) < NOTICEABLE) {
      continue;
    }

    const kind: InsightKind = value.change_is_good
      ? 'improved'
      : Math.abs(ratio) >= ATTENTION
        ? 'attention'
        : 'declined';

    cards.push({
      key: metric,
      kind,
      value,
      subject: null,
      weight: (kind === 'attention' ? 10 : 0) + Math.abs(ratio),
    });
  }

  const ranked = cards.sort((a, b) => b.weight - a.weight).slice(0, MAX_INSIGHTS);

  const best = campaignCards(report).top;
  const lead = best[0];
  const first = breakdown(report, 'campaign')?.metrics[0];

  /* Three campaigns is the least that makes "best" mean something. */
  if (
    lead !== undefined &&
    first !== undefined &&
    (breakdown(report, 'campaign')?.rows.length ?? 0) >= 3
  ) {
    const value = lead.values[first];

    if (value !== undefined && value.value !== null && value.value > 0) {
      ranked.push({ key: `best-${lead.key}`, kind: 'best', value, subject: lead.label, weight: 0 });
    }
  }

  return ranked;
}

export function recommendations(report: RenderedReport): Recommendation[] {
  const out: Recommendation[] = [];
  const account = scorecardValues(report);

  /*
   * A platform returning clearly more than the report's own figure. Said as something to look at, with both figures
   * beside it: platforms are not interchangeable clicks, so this is a pointer, not a verdict.
   */
  const platforms = breakdown(report, 'provider');
  const roas = account['roas'];

  if (platforms !== null && roas !== undefined && roas.value !== null && roas.value > 0) {
    const rows = platforms.rows.filter(
      (row) => (row.metrics.values['roas']?.value ?? null) !== null,
    );
    const best = [...rows].sort(
      (a, b) => (b.metrics.values['roas']?.value ?? 0) - (a.metrics.values['roas']?.value ?? 0),
    )[0];
    const value = best?.metrics.values['roas'];

    if (
      rows.length >= 2 &&
      best !== undefined &&
      value?.value != null &&
      value.value >= roas.value * LEADING
    ) {
      out.push({ key: 'allocate', subject: best.label, current: value, against: roas });
    }
  }

  /* A click-through rate that fell by a noticeable margin: the creative is the first suspect. */
  const ctr = account['ctr'];

  if (
    ctr !== undefined &&
    ctr.change_ratio !== null &&
    ctr.change_is_good === false &&
    ctr.change_ratio <= -ATTENTION
  ) {
    out.push({ key: 'creative', subject: null, current: ctr, against: null });
  }

  return out;
}

/**
 * The stages a client's audience passed through, using only stages the report actually carries.
 *
 * A funnel is a claim that each stage is the next one's source. This product holds aggregate counts, not people, so
 * the claim is read loosely — a step can exceed the one before it (a purchase after a view, not a click) and the
 * bar says so by showing no rate there rather than a rate above a hundred. A stage the report was asked for and
 * could not read is kept, as not tracked; a stage nobody asked for is not invented.
 */
export type FunnelStage = { key: string; value: MetricValue };

const OUTCOMES = ['purchases', 'leads', 'conversions'] as const;
const COMMERCE_STEPS = ['add_to_cart', 'checkouts'] as const;

export function funnelStages(report: RenderedReport): FunnelStage[] | null {
  const values = scorecardValues(report);
  const seen = values['impressions'];
  const clicked = values['clicks'];

  if (seen?.value == null || clicked?.value == null) {
    return null;
  }

  const outcome = OUTCOMES.find((metric) => values[metric] !== undefined);

  if (outcome === undefined) {
    return null;
  }

  const middle =
    outcome === 'purchases' ? COMMERCE_STEPS.filter((metric) => values[metric] !== undefined) : [];
  const keys = ['impressions', 'clicks', ...middle, outcome];

  return keys.flatMap((key) => {
    const value = values[key];

    return value === undefined ? [] : [{ key, value }];
  });
}
